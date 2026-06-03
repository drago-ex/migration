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
		if (!$this->repository->acquireLock('db_migrations')) {
			throw new \RuntimeException('Unable to acquire migration lock. Make sure no other session holds the lock.');
		}

		try {
			$this->ensureMigrationsTable($logger);

			if (is_dir($path)) {
				$sqlFiles = glob(rtrim($path, '/') . '/*.sql');

				if ($sqlFiles === false) {
					throw new \RuntimeException(sprintf('Unable to read directory "%s".', $path));
				}

				sort($sqlFiles);

				foreach ($sqlFiles as $file) {
					$this->runMigration($file, $logger);
				}
			} elseif (is_file($path)) {
				$this->runMigration($path, $logger);

			} else {
				throw new \RuntimeException(sprintf('Path "%s" does not exist.', $path));
			}

		} finally {
			$this->repository->releaseLock('db_migrations');
		}
	}


	/**
	 * @throws Exception
	 * @throws Throwable
	 * @throws DriverException
	 */
	private function runMigration(string $sqlFile, ?callable $logger = null): void
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

		$migrationFile = basename($sqlFile);
		if (!is_file($sqlFile) || !is_readable($sqlFile)) {
			throw new \RuntimeException(sprintf('SQL file "%s" does not exist or is not readable.', $sqlFile));
		}

		$checksum = sha1_file($sqlFile);
		if ($checksum === false) {
			throw new \RuntimeException(sprintf('Unable to calculate checksum for "%s".', $sqlFile));
		}

		$existingChecksum = $this->repository->getMigrationChecksum($package, $migrationFile);
		if ($existingChecksum !== null) {
			if ($existingChecksum !== $checksum) {
				$this->log($logger, '<error>ERROR Migration "' . $migrationFile . '" failed: checksum mismatch.</error>');
				throw new \RuntimeException('Migration "' . $migrationFile . '" was modified after execution.');
			}

			$this->log($logger, '<comment>SKIP Migration "' . $migrationFile . '" already executed.</comment>');
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
	private function ensureMigrationsTable(?callable $logger = null): void
	{
		if ($this->repository->migrationsTableExists()) {
			return;
		}

		$sqlFile = dirname(__DIR__, 3) . '/migrations/000_migrations.sql';
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
