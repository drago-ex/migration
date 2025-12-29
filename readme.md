## Drago Migration
A simple CLI tool for running SQL migrations.

Drago Migration is a lightweight CLI tool built on Nette, Dibi and Symfony Console.
It allows you to run SQL migrations from files or directories with checksum validation, transactional execution and database-level locking.

## Requirements
- PHP >= 8.3
- Nette Framework
- Symfony Console
- dibi
- Composer

## Install
```bash
composer require drago/migration
```

## Register Migration Extension in Nette
```php
extensions:
    migration: Drago\Migration\DI\MigrationExtension(%consoleMode%)
    console: Contributte\Console\DI\ConsoleExtension(%consoleMode%)
```
Make sure the %consoleMode% parameter is available (usually already present in Nette CLI setups).

## Database Setup
Create the migrations table in your database:
```sql
CREATE TABLE IF NOT EXISTS migrations (
	id INT AUTO_INCREMENT PRIMARY KEY,
	package VARCHAR(255) NOT NULL,
	migration_file VARCHAR(255) NOT NULL,
	checksum CHAR(40) NOT NULL,
	executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uniq_migration (package, migration_file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

## Usage
Run migrations using the Composer-installed binary:
```bash
php vendor/bin/migration db:migrate <path>
```
Path to a single SQL file or a directory containing multiple .sql files.

## Examples
```bash
# Run all migrations in a folder
php vendor/bin/migration db:migrate migrations/

# Run a single migration file
php vendor/bin/migration db:migrate migrations/001_example.sql
```

## Features
- Checksum validation
Detects if a migration file was modified after execution.

- Transactional execution
Each migration runs inside a database transaction.

- Database locking
Prevents multiple migration processes from running concurrently.

- Package-aware migrations
Automatically detects migrations located in vendor/ and groups them by package.

- Symfony Console integration
Clean CLI output and proper exit codes.

## Notes
- This package is designed for Nette Framework projects.
- The provided CLI binary expects a Nette project structure with app/Bootstrap.php.
- For non-Nette projects, a custom bootstrap script is required.
