# Drago Migration

A simple CLI tool for running SQL migrations.
Drago Migration is a lightweight CLI tool built on Nette, Dibi and Symfony Console.
It allows you to run SQL migrations from files or directories with checksum validation, transactional execution and database-level locking.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/drago-ex/migration/blob/main/license)
[![PHP version](https://badge.fury.io/ph/drago-ex%2Fmigration.svg)](https://badge.fury.io/ph/drago-ex%2Fmigration)
[![Coding Style](https://github.com/drago-ex/migration/actions/workflows/coding-style.yml/badge.svg)](https://github.com/drago-ex/migration/actions/workflows/coding-style.yml)

## Requirements
- PHP >= 8.3
- Nette Framework
- Symfony Console
- dibi
- Composer

## Installation
```bash
composer require drago-ex/migration
```

## Examples
Run migrations using the Composer-installed binary:
```bash
php vendor/bin/migration db:migrate <path>
```
Path to a single SQL file or a directory containing multiple .sql files.

```bash
### Run all migrations in a folder
php vendor/bin/migration db:migrate migrations

### Run a single migration file
php vendor/bin/migration db:migrate migrations/001_example.sql
```

## Export SQL Migrations
Export migration SQL files from installed Composer packages:
```bash
php vendor/bin/sql-export
```
By default, SQL files are copied into the `migrations` directory.

Use an optional target directory argument to export files elsewhere:
```bash
php vendor/bin/sql-export db
php vendor/bin/sql-export temp/sql
```
Existing files are skipped, so the command can be safely run repeatedly.

## Automated Package Setup
Projects using [drago-ex/project-tools](https://github.com/drago-ex/project-tools) can run package-defined migration commands through:

```bash
php vendor/bin/drago-setup
```

Packages expose their setup commands in `composer.json` under `extra.drago-tools.commands`.
The migration package provides the migration runner; `drago-setup` only discovers and executes commands defined by installed packages.

## Register Migration Extension in Nette
```neon
extensions:
    migration: Drago\Migration\DI\MigrationExtension(%consoleMode%)
    console: Contributte\Console\DI\ConsoleExtension(%consoleMode%)

### Symfony Console
console:
	name: Symfony Console
	version: '1.0'
```
Make sure the %consoleMode% parameter is available (usually already present in Nette CLI setups).

## Database Setup
The migrations table is created automatically on the first migration run.
The table is created from the package SQL file `migrations/000_migrations.sql`:
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
- This package is designed for Nette Framework projects.
- The provided CLI binary expects a Nette project structure with app/Bootstrap.php.
- For non-Nette projects, a custom bootstrap script is required.
