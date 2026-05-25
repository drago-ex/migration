<?php

declare(strict_types=1);

namespace Drago\Migration;

use Dibi\Connection;
use Dibi\DriverException;
use Dibi\Exception;
use Dibi\Row;


/** Handles database operations for migrations. */
readonly class Repository
{
	public function __construct(
		private Connection $connection,
	) {
	}


	/** Acquire a DB lock for migrations. */
	public function acquireLock(string $name, int $timeout = 30): bool
	{
		$result = $this->connection
			->select('GET_LOCK(%s, %i)', $name, $timeout)
			->fetchSingle();

		return $result === 1 || $result === '1';
	}


	/** Release the DB lock. */
	public function releaseLock(string $name): void
	{
		$released = $this->connection
			->select('RELEASE_LOCK(%s)', $name)
			->fetchSingle();

		if ($released !== 1 && $released !== '1') {
			throw new \RuntimeException('Unable to release migration lock.');
		}
	}


	/** Check if the migrations table exists. */
	public function migrationsTableExists(): bool
	{
		return (bool) $this->connection
			->select('1')
			->from('[INFORMATION_SCHEMA].[TABLES]')
			->where('TABLE_SCHEMA = DATABASE()')
			->and('TABLE_NAME = %s', 'migrations')
			->limit(1)
			->fetchSingle();
	}


	/** Get the checksum of a previously executed migration. */
	public function getMigrationChecksum(string $package, string $file): ?string
	{
		$row = $this->connection
			->select('*')
			->from('migrations')
			->where('package = %s', $package)
			->and('migration_file = %s', $file)
			->fetch();

		if (!$row instanceof Row) {
			return null;
		}

		return isset($row['checksum']) ? (string) $row['checksum'] : null;
	}


	/**
	 * Insert a new migration record.
	 * @throws Exception
	 */
	public function insertMigration(string $package, string $file, string $checksum): void
	{
		$this->connection
			->insert('migrations', [
				'package' => $package,
				'migration_file' => $file,
				'checksum' => $checksum,
			])
			->execute();
	}


	/**
	 * Begin a transaction.
	 * @throws DriverException
	 */
	public function begin(): void
	{
		$this->connection->begin();
	}


	/**
	 * Commit a transaction.
	 * @throws DriverException
	 */
	public function commit(): void
	{
		$this->connection->commit();
	}


	/**
	 * Rollback a transaction.
	 * @throws DriverException
	 */
	public function rollback(): void
	{
		$this->connection->rollback();
	}


	/** Execute SQL file. */
	public function runSqlFile(string $file): void
	{
		$this->connection->loadFile($file);
	}
}
