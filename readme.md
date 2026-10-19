# Drago Migration

A simple CLI tool for running SQL migrations.
Drago Migration is a lightweight CLI tool built on Nette, Dibi and Symfony Console.
It allows you to run SQL migrations from files or directories with checksum validation, transactional execution and database-level locking.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/drago-ex/migration/blob/main/license)
[![PHP version](https://badge.fury.io/ph/drago-ex%2Fmigration.svg)](https://badge.fury.io/ph/drago-ex%2Fmigration)
[![Tests](https://github.com/drago-ex/migration/actions/workflows/tests.yml/badge.svg)](https://github.com/drago-ex/migration/actions/workflows/tests.yml)
[![Coding Style](https://github.com/drago-ex/migration/actions/workflows/coding-style.yml/badge.svg)](https://github.com/drago-ex/migration/actions/workflows/coding-style.yml)

## Requirements
- PHP >= 8.3, < 9
- Nette Framework
- Symfony Console
- dibi
- MySQL or MariaDB
- Composer

## Installation
```bash
composer require drago-ex/migration
```

## Register Migration Extension in Nette
```neon
extensions:
    migration: Drago\Migration\DI\MigrationExtension(%consoleMode%)
    console: Contributte\Console\DI\ConsoleExtension(%consoleMode%)

# Symfony Console
console:
	name: Symfony Console
	version: '1.0'
```
Make sure the %consoleMode% parameter is available (usually already present in Nette CLI setups).

## Examples
```bash
# Run the migrations of all installed packages in their order, then the project's migrations/
php vendor/bin/migration db:migrate --packages

# Run all migrations in a folder
php vendor/bin/migration db:migrate migrations

# Run a single migration file
php vendor/bin/migration db:migrate migrations/001_example.sql
```
A migration that already ran is never run again.

## Order of Package Migrations
With `--packages`, the migrations run straight from `vendor/` (nothing is copied): packages ordered by
`migration-priority` (lower first, default 100) and name, then the project's own `migrations/`.
A package that uses tables of another package needs a higher priority:

```json
"extra": {
    "drago-tools": {
        "migration-priority": 30
    }
}
```
Drago packages use 10 for base tables (`users` of drago-ex/project-auth), 20 for packages built on them
(drago-ex/project-permission) and the default 100 for the rest.

## Record Existing Migrations
Records migrations as executed without running them, for a database whose tables already exist:
```bash
php vendor/bin/migration db:baseline --packages
```

## Report
With `--packages`, the result of the last run is written to `.drago-project/migration.json`.
It differs per database, so add it to `.gitignore`.

## Export SQL Migrations (optional)
Copies the SQL files of installed packages into the project:
```bash
php vendor/bin/sql-export
php vendor/bin/sql-export temp/sql
```
By default, SQL files are copied into the `migrations` directory. Existing files are skipped.

## Database Setup
The migrations table is created automatically on the first migration run,
from the package SQL file `migrations/000_migrations.sql`:
```sql
CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    package VARCHAR(255) NOT NULL,
    migration_file VARCHAR(255) NOT NULL,
    checksum CHAR(40) NOT NULL,
    executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_migrations_package_file
        UNIQUE (package, migration_file)
)
    ENGINE = InnoDB
    DEFAULT CHARSET = utf8mb4
    COLLATE = utf8mb4_unicode_ci;
```

## Features
- Checksum validation - detects modified migrations
- Transactional execution - safe rollback on failure
- Database locking - prevents concurrent runs
- Package-aware migrations - supports vendor-based migrations
- Automatic migrations table setup on first run
- Symfony Console integration - clean CLI output

## Notes
- MySQL commits `CREATE`, `ALTER` and `DROP` immediately, so keep one schema change per file.
- Line endings do not count as a change of a migration.
- The provided CLI binary expects a Nette project structure with app/Bootstrap.php.
- For non-Nette projects, a custom bootstrap script is required.
