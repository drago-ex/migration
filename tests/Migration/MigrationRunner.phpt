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
	}


	public function migrationsTableExists(): bool
	{
		return true;
	}


	public function getMigrationChecksum(string $package, string $file): ?string
	{
		return $GLOBALS['migrationChecksums'][$file] ?? null;
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
