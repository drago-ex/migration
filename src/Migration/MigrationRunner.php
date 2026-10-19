<?php

declare(strict_types=1);

namespace Drago\Migration;

use Dibi\DriverException;
use Dibi\Exception;
use Throwable;


/** Migration runner for executing SQL migration files safely. */
readonly class MigrationRunner
{
	public function __construct(
		private Repository $repository,
	) {
	}


	/**
	 * Run migrations from a file, a directory (by file name), or a list of files in its order
	 * (e.g. MigrationFiles::forProject()). The outcome of each file is added to the report, when given.
	 * @param string|list<string> $path
	 * @throws Exception
	 * @throws Throwable
	 */
	public function run(string|array $path, ?callable $logger = null, ?MigrationReport $report = null): void
	{
		$this->process($path, $logger, $report, $this->runMigration(...));
	}


	/**
	 * Records migrations from a file, a directory or a list of files as executed, without running their SQL.
	 * For a database whose schema was created another way, e.g. by an older web installer.
	 * @param string|list<string> $path
	 * @throws Exception
	 * @throws Throwable
	 */
	public function baseline(string|array $path, ?callable $logger = null, ?MigrationReport $report = null): void
	{
		$this->process($path, $logger, $report, $this->baselineMigration(...));
	}


	/**
	 * Handles a file, every .sql file of a directory, or a list of files under the migration lock.
	 * @param string|list<string> $path
	 * @param callable(string, ?callable, ?MigrationReport): void $handler
	 * @throws Throwable
	 */
	private function process(string|array $path, ?callable $logger, ?MigrationReport $report, callable $handler): void
	{
		if (is_array($path)) {
			foreach ($path as $file) {
				if (!is_file($file)) {
					throw new \RuntimeException(sprintf('Path "%s" does not exist.', $file));
				}
			}
		}

		if (!$this->repository->acquireLock('db_migrations')) {
			throw new \RuntimeException('Unable to acquire migration lock. Make sure no other session holds the lock.');
		}

		$failure = null;
		try {
			$this->ensureMigrationsTable($logger, $report);

			if (is_array($path)) {
				foreach ($path as $file) {
					$handler($file, $logger, $report);
				}
			} elseif (is_dir($path)) {
				foreach (MigrationFiles::list($path) as $file) {
					$handler($file, $logger, $report);
				}
			} elseif (is_file($path)) {
				$handler($path, $logger, $report);

			} else {
				throw new \RuntimeException(sprintf('Path "%s" does not exist.', $path));
			}

		} catch (Throwable $failure) {
			throw $failure;

		} finally {
			try {
				$this->repository->releaseLock('db_migrations');
			} catch (Throwable $releaseFailure) {
				// A failed release must not replace the error of the migration itself.
				if ($failure === null) {
					throw $releaseFailure;
				}
			}
		}
	}


	/**
	 * @throws Exception
	 * @throws Throwable
	 * @throws DriverException
	 */
	private function runMigration(string $sqlFile, ?callable $logger = null, ?MigrationReport $report = null): void
	{
		[$package, $migrationFile, $checksum] = $this->describe($sqlFile);
		if ($this->isRecorded($package, $migrationFile, $checksum, $sqlFile, $logger, $report)) {
			return;
		}

		try {
			$this->repository->begin();
			$this->repository->runSqlFile($sqlFile);
			$this->repository->insertMigration($package, $migrationFile, $checksum);
			$this->repository->commit();
			$this->log($logger, '<info>OK Migration "' . $migrationFile . '" executed successfully.</info>');
			$report?->add($package, $sqlFile, 'executed');

		} catch (Throwable $e) {
			$this->repository->rollback();
			$this->log($logger, '<error>ERROR Migration "' . $migrationFile . '" failed: ' . $e->getMessage() . '</error>');
			$report?->add($package, $sqlFile, 'failed', $e->getMessage());
			throw $e;
		}
	}


	/**
	 * @throws Exception
	 */
	private function baselineMigration(string $sqlFile, ?callable $logger = null, ?MigrationReport $report = null): void
	{
		[$package, $migrationFile, $checksum] = $this->describe($sqlFile);
		if ($this->isRecorded($package, $migrationFile, $checksum, $sqlFile, $logger, $report)) {
			return;
		}

		$this->repository->insertMigration($package, $migrationFile, $checksum);
		$this->log($logger, '<info>OK Migration "' . $migrationFile . '" recorded without running it.</info>');
		$report?->add($package, $sqlFile, 'recorded');
	}


	/**
	 * Returns the package the migration is recorded under (`<vendor>/<package>` for files
	 * inside vendor/, otherwise `core`), its file name and checksum.
	 * @return array{string, string, string}
	 */
	private function describe(string $sqlFile): array
	{
		$package = 'core';
		$normalizedPath = str_replace('\\', '/', $sqlFile);

		$vendorPos = strpos($normalizedPath, 'vendor/');
		if ($vendorPos !== false) {
			$relative = substr($normalizedPath, $vendorPos);
			$parts = explode('/', $relative);

			if (isset($parts[1], $parts[2])) {
				$package = $parts[1] . '/' . $parts[2];
			}
		}

		if (!is_file($sqlFile) || !is_readable($sqlFile)) {
			throw new \RuntimeException(sprintf('SQL file "%s" does not exist or is not readable.', $sqlFile));
		}

		$checksum = sha1_file($sqlFile);
		if ($checksum === false) {
			throw new \RuntimeException(sprintf('Unable to calculate checksum for "%s".', $sqlFile));
		}

		return [$package, basename($sqlFile), $checksum];
	}


	/**
	 * True when the migration is already recorded; a recorded file that was changed is an error.
	 * @throws Exception
	 */
	private function isRecorded(
		string $package,
		string $migrationFile,
		string $checksum,
		string $sqlFile,
		?callable $logger,
		?MigrationReport $report,
	): bool
	{
		$existingChecksum = $this->repository->getMigrationChecksum($package, $migrationFile);
		if ($existingChecksum === null) {
			return $this->isRecordedElsewhere($package, $migrationFile, $checksum, $sqlFile, $logger, $report);
		}

		if (!in_array($existingChecksum, $this->checksumVariants($checksum, $sqlFile), true)) {
			$this->log($logger, '<error>ERROR Migration "' . $migrationFile . '" failed: checksum mismatch.</error>');
			$report?->add($package, $sqlFile, 'failed', 'Modified after execution (checksum mismatch).');
			throw new \RuntimeException('Migration "' . $migrationFile . '" was modified after execution.');
		}

		$this->log($logger, '<comment>SKIP Migration "' . $migrationFile . '" already executed.</comment>');
		$report?->add($package, $sqlFile, 'skipped');
		return true;
	}


	/**
	 * @throws Exception
	 */
	private function ensureMigrationsTable(?callable $logger = null, ?MigrationReport $report = null): void
	{
		if ($this->repository->migrationsTableExists()) {
			return;
		}

		$sqlFile = dirname(__DIR__, 2) . '/migrations/000_migrations.sql';
		if (!is_file($sqlFile) || !is_readable($sqlFile)) {
			throw new \RuntimeException(sprintf('Migration table SQL file "%s" does not exist or is not readable.', $sqlFile));
		}

		$checksum = sha1_file($sqlFile);
		if ($checksum === false) {
			throw new \RuntimeException(sprintf('Unable to calculate checksum for "%s".', $sqlFile));
		}

		$this->repository->runSqlFile($sqlFile);
		$this->repository->insertMigration('drago-ex/migration', basename($sqlFile), $checksum);
		$this->log($logger, '<info>OK Migration table created.</info>');
		$report?->add('drago-ex/migration', $sqlFile, 'executed');
	}


	/**
	 * A package migration can run from vendor/ (recorded under the package) or exported into the project's
	 * migrations/ (recorded as core). The same file with the same content that already ran from the other place
	 * is not run again, only recorded for this one, so both ways can be used in one project.
	 */
	private function isRecordedElsewhere(
		string $package,
		string $migrationFile,
		string $checksum,
		string $sqlFile,
		?callable $logger,
		?MigrationReport $report,
	): bool
	{
		$checksums = $this->checksumVariants($checksum, $sqlFile);
		if (array_intersect($checksums, $this->repository->getChecksumsElsewhere($migrationFile, $package)) === []) {
			return false;
		}

		$this->repository->insertMigration($package, $migrationFile, $checksum);
		$this->log($logger, '<comment>SKIP Migration "' . $migrationFile . '" already executed from another location.</comment>');
		$report?->add($package, $sqlFile, 'skipped', 'Already executed from another location.');
		return true;
	}


	/**
	 * Checksums of the file with LF and with CRLF line endings. Git may change line endings on checkout
	 * (e.g. on Windows), which is still the same migration and must not count as a change.
	 * @return list<string>
	 */
	private function checksumVariants(string $checksum, string $sqlFile): array
	{
		$content = str_replace("\r\n", "\n", (string) file_get_contents($sqlFile));
		return [$checksum, sha1($content), sha1(str_replace("\n", "\r\n", $content))];
	}


	private function log(?callable $logger, string $message): void
	{
		if ($logger !== null) {
			$logger($message);
		}
	}
}
