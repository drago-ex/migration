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
	 * Run migrations from a file or a directory.
	 * @throws Exception
	 * @throws Throwable
	 */
	public function run(string $path, ?callable $logger = null): void
	{
		$this->process($path, $logger, $this->runMigration(...));
	}


	/**
	 * Records migrations from a file or a directory as executed, without running their SQL.
	 * For a database whose schema was created another way, e.g. by an older web installer.
	 * @throws Exception
	 * @throws Throwable
	 */
	public function baseline(string $path, ?callable $logger = null): void
	{
		$this->process($path, $logger, $this->baselineMigration(...));
	}


	/**
	 * Handles a file or every .sql file of a directory under the migration lock.
	 * @param callable(string, ?callable): void $handler
	 * @throws Throwable
	 */
	private function process(string $path, ?callable $logger, callable $handler): void
	{
		if (!$this->repository->acquireLock('db_migrations')) {
			throw new \RuntimeException('Unable to acquire migration lock. Make sure no other session holds the lock.');
		}

		$failure = null;
		try {
			$this->ensureMigrationsTable($logger);

			if (is_dir($path)) {
				foreach (MigrationFiles::list($path) as $file) {
					$handler($file, $logger);
				}
			} elseif (is_file($path)) {
				$handler($path, $logger);

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
	private function runMigration(string $sqlFile, ?callable $logger = null): void
	{
		[$package, $migrationFile, $checksum] = $this->describe($sqlFile);
		if ($this->isRecorded($package, $migrationFile, $checksum, $logger)) {
			return;
		}

		try {
			$this->repository->begin();
			$this->repository->runSqlFile($sqlFile);
			$this->repository->insertMigration($package, $migrationFile, $checksum);
			$this->repository->commit();
			$this->log($logger, '<info>OK Migration "' . $migrationFile . '" executed successfully.</info>');

		} catch (Throwable $e) {
			$this->repository->rollback();
			$this->log($logger, '<error>ERROR Migration "' . $migrationFile . '" failed: ' . $e->getMessage() . '</error>');
			throw $e;
		}
	}


	/**
	 * @throws Exception
	 */
	private function baselineMigration(string $sqlFile, ?callable $logger = null): void
	{
		[$package, $migrationFile, $checksum] = $this->describe($sqlFile);
		if ($this->isRecorded($package, $migrationFile, $checksum, $logger)) {
			return;
		}

		$this->repository->insertMigration($package, $migrationFile, $checksum);
		$this->log($logger, '<info>OK Migration "' . $migrationFile . '" recorded without running it.</info>');
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
	private function isRecorded(string $package, string $migrationFile, string $checksum, ?callable $logger): bool
	{
		$existingChecksum = $this->repository->getMigrationChecksum($package, $migrationFile);
		if ($existingChecksum === null) {
			return false;
		}

		if ($existingChecksum !== $checksum) {
			$this->log($logger, '<error>ERROR Migration "' . $migrationFile . '" failed: checksum mismatch.</error>');
			throw new \RuntimeException('Migration "' . $migrationFile . '" was modified after execution.');
		}

		$this->log($logger, '<comment>SKIP Migration "' . $migrationFile . '" already executed.</comment>');
		return true;
	}


	/**
	 * @throws Exception
	 */
	private function ensureMigrationsTable(?callable $logger = null): void
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
	}


	private function log(?callable $logger, string $message): void
	{
		if ($logger !== null) {
			$logger($message);
		}
	}
}
