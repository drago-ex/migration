<?php

/**
 * Test: Drago\Migration\MigrationRunner
 */

declare(strict_types=1);

use Drago\Migration\MigrationRunner;
use Drago\Migration\Repository;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


readonly class MigrationRunnerTestRepository extends Repository
{
	public function __construct(
		private bool $failOnSql = false,
	) {
	}


	public function acquireLock(string $name, int $timeout = 30): bool
	{
		$GLOBALS['migrationEvents'][] = ['lock', $name, $timeout];
		return $GLOBALS['migrationLock'] ?? true;
	}


	public function releaseLock(string $name): void
	{
		$GLOBALS['migrationEvents'][] = ['release', $name];
		if ($GLOBALS['migrationReleaseFails'] ?? false) {
			throw new RuntimeException('Unable to release migration lock.');
		}
	}


	public function migrationsTableExists(): bool
	{
		return $GLOBALS['migrationTableExists'] ?? true;
	}


	public function getMigrationChecksum(string $package, string $file): ?string
	{
		return $GLOBALS['migrationChecksums'][$file] ?? null;
	}


	public function getChecksumsElsewhere(string $file, string $package): array
	{
		return $GLOBALS['migrationChecksumsElsewhere'][$file] ?? [];
	}


	public function begin(): void
	{
		$GLOBALS['migrationEvents'][] = ['begin'];
	}


	public function runSqlFile(string $file): void
	{
		if ($this->failOnSql) {
			throw new RuntimeException('SQL execution failed.');
		}

		$GLOBALS['migrationEvents'][] = ['sql', basename($file)];
	}


	public function insertMigration(string $package, string $file, string $checksum): void
	{
		$GLOBALS['migrationEvents'][] = ['insert', $package, $file, $checksum];
		$GLOBALS['migrationChecksums'][$file] = $checksum;
	}


	public function commit(): void
	{
		$GLOBALS['migrationEvents'][] = ['commit'];
	}


	public function rollback(): void
	{
		$GLOBALS['migrationEvents'][] = ['rollback'];
	}
}


test('Migrations run in filename order and are skipped after recording their checksum', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);

	try {
		file_put_contents($directory . '/002_second.sql', 'SELECT 2;');
		file_put_contents($directory . '/001_first.sql', 'SELECT 1;');
		$runner = new MigrationRunner(new MigrationRunnerTestRepository);
		$runner->run($directory);
		$firstEvents = $GLOBALS['migrationEvents'];

		Assert::same(['lock', 'begin', 'sql', 'insert', 'commit', 'begin', 'sql', 'insert', 'commit', 'release'], array_column($firstEvents, 0));
		Assert::same('001_first.sql', $firstEvents[2][1]);
		Assert::same('002_second.sql', $firstEvents[6][1]);

		$GLOBALS['migrationEvents'] = [];
		$runner->run($directory);
		Assert::same(['lock', 'release'], array_column($GLOBALS['migrationEvents'], 0));
	} finally {
		unlink($directory . '/001_first.sql');
		unlink($directory . '/002_second.sql');
		rmdir($directory);
	}
});


test('Migrations of a directory follow db-migration-order.json', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);

	try {
		file_put_contents($directory . '/001_roles.sql', 'SELECT 1;');
		file_put_contents($directory . '/001_users.sql', 'SELECT 2;');
		Drago\Migration\MigrationFiles::writeOrder($directory, ['001_users.sql', '001_roles.sql']);

		(new MigrationRunner(new MigrationRunnerTestRepository))->run($directory);
		$run = array_values(array_filter($GLOBALS['migrationEvents'], fn(array $event): bool => $event[0] === 'sql'));
		Assert::same(['001_users.sql', '001_roles.sql'], array_column($run, 1));
	} finally {
		array_map('unlink', glob($directory . '/*'));
		rmdir($directory);
	}
});


test('An exported migration that already ran from its package is recorded, not run again', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);
	$same = $directory . '/001_users.sql';
	$changed = $directory . '/002_roles.sql';
	file_put_contents($same, 'SELECT 1;');
	file_put_contents($changed, 'SELECT 2;');
	$GLOBALS['migrationChecksumsElsewhere'] = [
		'001_users.sql' => [sha1_file($same)],
		'002_roles.sql' => ['other-checksum'],
	];

	try {
		(new MigrationRunner(new MigrationRunnerTestRepository))->run($directory);
		$events = $GLOBALS['migrationEvents'];

		Assert::contains(['insert', 'core', '001_users.sql', sha1_file($same)], $events);
		Assert::notContains(['sql', '001_users.sql'], $events);
		Assert::contains(['sql', '002_roles.sql'], $events);
	} finally {
		unset($GLOBALS['migrationChecksumsElsewhere']);
		unlink($same);
		unlink($changed);
		rmdir($directory);
	}
});


test('A package migration run from vendor/ after it ran from migrations/ is recorded, not run again', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8)) . '/vendor/acme/users/migrations';
	mkdir($directory, recursive: true);
	$file = $directory . '/001_users.sql';
	file_put_contents($file, 'SELECT 1;');
	$GLOBALS['migrationChecksumsElsewhere'] = ['001_users.sql' => [sha1_file($file)]];

	try {
		(new MigrationRunner(new MigrationRunnerTestRepository))->run($directory);
		$events = $GLOBALS['migrationEvents'];

		Assert::contains(['insert', 'acme/users', '001_users.sql', sha1_file($file)], $events);
		Assert::notContains(['sql', '001_users.sql'], $events);
	} finally {
		unset($GLOBALS['migrationChecksumsElsewhere']);
		unlink($file);
	}
});


test('A copy that differs only in line endings counts as the same migration', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);
	$file = $directory . '/001_users.sql';
	file_put_contents($file, "CREATE TABLE users (\n    id INT\n);\n");
	$GLOBALS['migrationChecksumsElsewhere'] = ['001_users.sql' => [sha1("CREATE TABLE users (\r\n    id INT\r\n);\r\n")]];

	try {
		(new MigrationRunner(new MigrationRunnerTestRepository))->run($file);
		Assert::notContains(['sql', '001_users.sql'], $GLOBALS['migrationEvents']);
		Assert::contains(['insert', 'core', '001_users.sql', sha1_file($file)], $GLOBALS['migrationEvents']);
	} finally {
		unset($GLOBALS['migrationChecksumsElsewhere']);
		unlink($file);
		rmdir($directory);
	}
});


test('A modified migration fails and releases the lock', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = ['001_changed.sql' => 'old-checksum'];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);
	$file = $directory . '/001_changed.sql';
	file_put_contents($file, 'SELECT changed;');

	try {
		$runner = new MigrationRunner(new MigrationRunnerTestRepository);
		Assert::exception(
			fn() => $runner->run($file),
			RuntimeException::class,
			'Migration "001_changed.sql" was modified after execution.',
		);
		Assert::same('release', $GLOBALS['migrationEvents'][array_key_last($GLOBALS['migrationEvents'])][0]);
	} finally {
		unlink($file);
		rmdir($directory);
	}
});


test('A migration failure rolls back and releases the lock', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);
	$file = $directory . '/001_failure.sql';
	file_put_contents($file, 'SELECT failure;');

	try {
		$runner = new MigrationRunner(new MigrationRunnerTestRepository(failOnSql: true));
		Assert::exception(fn() => $runner->run($file), RuntimeException::class, 'SQL execution failed.');
		Assert::contains(['rollback'], $GLOBALS['migrationEvents']);
		Assert::same('release', $GLOBALS['migrationEvents'][array_key_last($GLOBALS['migrationEvents'])][0]);
	} finally {
		unlink($file);
		rmdir($directory);
	}
});


test('Nothing runs when the lock cannot be acquired', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationLock'] = false;

	try {
		$runner = new MigrationRunner(new MigrationRunnerTestRepository);
		Assert::exception(
			fn() => $runner->run(__DIR__),
			RuntimeException::class,
			'Unable to acquire migration lock.%a%',
		);
		Assert::same(['lock'], array_column($GLOBALS['migrationEvents'], 0));
	} finally {
		unset($GLOBALS['migrationLock']);
	}
});


test('A failure to release the lock does not hide the migration error', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$GLOBALS['migrationReleaseFails'] = true;
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);
	$file = $directory . '/001_failure.sql';
	file_put_contents($file, 'SELECT failure;');

	try {
		$runner = new MigrationRunner(new MigrationRunnerTestRepository(failOnSql: true));
		Assert::exception(fn() => $runner->run($file), RuntimeException::class, 'SQL execution failed.');
	} finally {
		unset($GLOBALS['migrationReleaseFails']);
		unlink($file);
		rmdir($directory);
	}
});


test('A failure to release the lock is reported after successful migrations', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$GLOBALS['migrationReleaseFails'] = true;
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);
	$file = $directory . '/001_ok.sql';
	file_put_contents($file, 'SELECT 1;');

	try {
		$runner = new MigrationRunner(new MigrationRunnerTestRepository);
		Assert::exception(fn() => $runner->run($file), RuntimeException::class, 'Unable to release migration lock.');
	} finally {
		unset($GLOBALS['migrationReleaseFails']);
		unlink($file);
		rmdir($directory);
	}
});


test('The migrations table is created first and recorded under this package', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$GLOBALS['migrationTableExists'] = false;
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);

	try {
		(new MigrationRunner(new MigrationRunnerTestRepository))->run($directory);
		Assert::same(['sql', '000_migrations.sql'], $GLOBALS['migrationEvents'][1]);
		Assert::same(['insert', 'drago-ex/migration', '000_migrations.sql'], array_slice($GLOBALS['migrationEvents'][2], 0, 3));
	} finally {
		unset($GLOBALS['migrationTableExists']);
		rmdir($directory);
	}
});


test('Migrations inside vendor are recorded under their package name', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8)) . '/vendor/acme/shop/migrations';
	mkdir($directory, 0o777, true);
	$file = $directory . '/001_orders.sql';
	file_put_contents($file, 'SELECT 1;');

	try {
		(new MigrationRunner(new MigrationRunnerTestRepository))->run($file);
		$insert = array_values(array_filter($GLOBALS['migrationEvents'], fn(array $event): bool => $event[0] === 'insert'))[0];
		Assert::same(['insert', 'acme/shop', '001_orders.sql'], array_slice($insert, 0, 3));
	} finally {
		unlink($file);
	}
});


test('Baseline records migrations without running their SQL', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);
	file_put_contents($directory . '/001_first.sql', 'SELECT 1;');
	file_put_contents($directory . '/002_second.sql', 'SELECT 2;');

	try {
		$runner = new MigrationRunner(new MigrationRunnerTestRepository);
		$runner->baseline($directory);
		Assert::same(['lock', 'insert', 'insert', 'release'], array_column($GLOBALS['migrationEvents'], 0));
		Assert::same(['001_first.sql', '002_second.sql'], array_keys($GLOBALS['migrationChecksums']));

		// A later run skips the recorded files
		$GLOBALS['migrationEvents'] = [];
		$runner->run($directory);
		Assert::same(['lock', 'release'], array_column($GLOBALS['migrationEvents'], 0));
	} finally {
		unlink($directory . '/001_first.sql');
		unlink($directory . '/002_second.sql');
		rmdir($directory);
	}
});


test('Baseline refuses a recorded migration that was changed', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = ['001_changed.sql' => 'old-checksum'];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);
	$file = $directory . '/001_changed.sql';
	file_put_contents($file, 'SELECT changed;');

	try {
		$runner = new MigrationRunner(new MigrationRunnerTestRepository);
		Assert::exception(fn() => $runner->baseline($file), RuntimeException::class, 'Migration "001_changed.sql" was modified after execution.');
	} finally {
		unlink($file);
		rmdir($directory);
	}
});
