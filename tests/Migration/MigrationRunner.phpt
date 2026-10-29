<?php declare(strict_types=1);

/**
 * Test: Drago\Migration\MigrationRunner
 */

use Drago\Migration\MigrationReport;
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

		Assert::same(
			['lock', 'begin', 'sql', 'insert', 'commit', 'begin', 'sql', 'insert', 'commit', 'release'],
			array_column($firstEvents, 0),
		);
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


test('A list of files runs in its order', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);

	try {
		file_put_contents($directory . '/001_roles.sql', 'SELECT 1;');
		file_put_contents($directory . '/001_users.sql', 'SELECT 2;');

		$files = [$directory . '/001_users.sql', $directory . '/001_roles.sql'];
		(new MigrationRunner(new MigrationRunnerTestRepository))->run($files);
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


test('A recorded migration whose line endings changed is not taken as modified', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = ['001_users.sql' => sha1("CREATE TABLE users (\r\n    id INT\r\n);\r\n")];
	$directory = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($directory);
	$file = $directory . '/001_users.sql';
	file_put_contents($file, "CREATE TABLE users (\n    id INT\n);\n");

	try {
		(new MigrationRunner(new MigrationRunnerTestRepository))->run($file);
		Assert::same(['lock', 'release'], array_column($GLOBALS['migrationEvents'], 0));
	} finally {
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
		Assert::exception(
			fn() => $runner->baseline($file),
			RuntimeException::class,
			'Migration "001_changed.sql" was modified after execution.',
		);
	} finally {
		unlink($file);
		rmdir($directory);
	}
});


test('The report records what each migration did, with paths relative to the project', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$GLOBALS['migrationTableExists'] = false;
	$root = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	$vendor = $root . '/vendor/acme/users/migrations';
	mkdir($vendor, 0o777, true);
	mkdir($root . '/migrations');
	file_put_contents($vendor . '/001_users.sql', 'SELECT 1;');
	file_put_contents($root . '/migrations/001_pages.sql', 'SELECT 2;');
	$files = [$vendor . '/001_users.sql', $root . '/migrations/001_pages.sql'];

	try {
		$runner = new MigrationRunner(new MigrationRunnerTestRepository);
		$report = new MigrationReport($root, 'migration db:migrate --packages');
		$runner->run($files, null, $report);

		Assert::same([
			[
				'package' => 'drago-ex/migration',
				'file' => str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__, 2)) . '/migrations/000_migrations.sql',
				'action' => 'executed',
			],
			['package' => 'acme/users', 'file' => 'vendor/acme/users/migrations/001_users.sql', 'action' => 'executed'],
			['package' => 'core', 'file' => 'migrations/001_pages.sql', 'action' => 'executed'],
		], $report->getEntries());

		// A second run skips both; the report is written into .drago-project/
		$GLOBALS['migrationTableExists'] = true;
		$report = new MigrationReport($root, 'migration db:migrate --packages');
		$runner->run($files, null, $report);
		Assert::true($report->write());

		$data = json_decode((string) file_get_contents($root . '/.drago-project/migration.json'), true);
		Assert::same('migration', $data['tool']);
		Assert::same('migration db:migrate --packages', $data['command']);
		Assert::same('ok', $data['result']);
		Assert::same(['executed' => 0, 'recorded' => 0, 'skipped' => 2, 'failed' => 0], $data['stats']);
		Assert::same(['skipped', 'skipped'], array_column($data['entries'], 'action'));
	} finally {
		unset($GLOBALS['migrationTableExists']);
	}
});


test('The report records a failed migration and the error of the run', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = ['001_changed.sql' => 'old-checksum'];
	$root = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($root);
	$file = $root . '/001_changed.sql';
	file_put_contents($file, 'SELECT changed;');

	$report = new MigrationReport($root, 'migration db:migrate --packages');
	Assert::exception(
		fn() => (new MigrationRunner(new MigrationRunnerTestRepository))->run($file, null, $report),
		RuntimeException::class,
	);
	$report->fail('Migration "001_changed.sql" was modified after execution.');

	$data = $report->toArray();
	Assert::same('error', $data['result']);
	Assert::same('Migration "001_changed.sql" was modified after execution.', $data['message']);
	Assert::same(1, $data['stats']['failed']);
	Assert::same(
		[
			'package' => 'core',
			'file' => '001_changed.sql',
			'action' => 'failed',
			'message' => 'Modified after execution (checksum mismatch).',
		],
		$data['entries'][0],
	);
});


test('Baseline and an executed elsewhere migration are reported', function () {
	$GLOBALS['migrationEvents'] = [];
	$GLOBALS['migrationChecksums'] = [];
	$root = sys_get_temp_dir() . '/migration-runner-' . bin2hex(random_bytes(8));
	mkdir($root);
	file_put_contents($root . '/001_users.sql', 'SELECT 1;');
	file_put_contents($root . '/002_roles.sql', 'SELECT 2;');
	$GLOBALS['migrationChecksumsElsewhere'] = ['001_users.sql' => [sha1_file($root . '/001_users.sql')]];

	try {
		$report = new MigrationReport($root, 'migration db:baseline --packages');
		(new MigrationRunner(new MigrationRunnerTestRepository))->baseline($root, null, $report);
		Assert::same([
			['package' => 'core', 'file' => '001_users.sql', 'action' => 'skipped', 'message' => 'Already executed from another location.'],
			['package' => 'core', 'file' => '002_roles.sql', 'action' => 'recorded'],
		], $report->getEntries());
	} finally {
		unset($GLOBALS['migrationChecksumsElsewhere']);
	}
});
