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

## Examples

Run migrations using the Composer-installed binary:
```bash
php vendor/bin/migration db:migrate <path>
```
Path to a single SQL file or a directory containing multiple .sql files.

```bash
# Run all migrations in a folder
php vendor/bin/migration db:migrate migrations

# Run a single migration file
php vendor/bin/migration db:migrate migrations/001_example.sql
```

## Record Existing Migrations

When the changes of some migrations are already in the database but not recorded in the `migrations`
table (for example a database set up by an older web installer), record them without running their SQL:

```bash
php vendor/bin/migration db:baseline migrations
```

Already recorded files are skipped and a recorded file that was changed is reported as an error, as with
`db:migrate`. Use it only for files whose changes really are in the database; `db:migrate` never runs a
recorded file again.

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
Existing files with the same content are skipped, so the command can be safely run repeatedly.

### Order of migrations

Migrations of different packages often depend on each other (a table of one package refers to a table of another),
so after copying the files `sql-export` writes their order into `db-migration-order.json` in the target directory:

- packages follow their `extra.drago-tools.commands-priority` from `composer.json` (lower first, default 100),
  the same order in which `drago-setup` runs their migration commands; packages with the same priority follow by name,
- the migrations of one package follow by file name.

`db:migrate`, `db:baseline` and the web installer of `drago-ex/project-install` run a directory in this order
(`Drago\Migration\MigrationFiles`). Files not listed there, such as the project's own migrations, run after them
by file name; without the file, all migrations run by file name. File names are not changed, so migrations
already recorded in the `migrations` table stay recorded.

When a package uses tables of another package, give it a higher `commands-priority` than that package.

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

# Symfony Console
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

- Checksum validation - a migration changed after it was executed stops the run
- Transactional execution - each migration runs in a transaction (see the note on DDL below)
- Database locking - `GET_LOCK()` prevents concurrent runs
- Package-aware migrations - files from `vendor/<vendor>/<package>/` are recorded under that package name
- Automatic migrations table setup on first run
- Symfony Console integration - clean CLI output

## Notes

- **MySQL or MariaDB only.** The runner uses `GET_LOCK()`, `RELEASE_LOCK()`, `INFORMATION_SCHEMA` and `DATABASE()`.
- **DDL is not rolled back.** MySQL commits statements like `CREATE`, `ALTER` and `DROP` immediately, so a
  migration that fails after such a statement stays partially applied and is not recorded. Keep one schema
  change per file, so a failed migration can be fixed and run again.
- **Where a migration is recorded.** A file inside `vendor/<vendor>/<package>/` is recorded under
  `<vendor>/<package>`, any other file under `core`, always together with its file name. Run each migration
  from one place only: a package migration first run from `vendor/` (for example by `drago-setup`) and later
  exported with `sql-export` into `migrations/` gets a different record and would run again.
- `sql-export` skips files that already exist with the same content. A different file with the same name,
  for example from another package, is reported as a conflict and the command ends with exit code 1.
- `vendor/bin/migration` runs from the project root and loads `app/Bootstrap.php`; it calls
  `App\Bootstrap::bootConsoleApplication()`, which must return the DI container with the migration and
  console extensions registered. For non-Nette projects, a custom bootstrap script is required.
