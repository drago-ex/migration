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
php vendor/bin/migration db:migrate --packages
```

```bash
# Run the migrations of all installed packages in their order, then the project's own migrations/
php vendor/bin/migration db:migrate --packages

# Run the migrations of one installed package straight from vendor/
php vendor/bin/migration db:migrate vendor/drago-ex/project-auth/migrations

# Run all migrations in a folder (by file name)
php vendor/bin/migration db:migrate migrations

# Run a single migration file
php vendor/bin/migration db:migrate migrations/001_example.sql
```

All ways can be combined in one project: a migration is never run twice, even when it runs from another place
the next time (see [Where a migration is recorded](#notes)).

## Order of Migrations

Migrations of different packages often depend on each other (a table of one package refers to a table of another).
With `--packages`, `db:migrate` collects the migrations straight from `vendor/`, nothing is copied:

1. installed packages that have a `migrations/` directory, ordered by `extra.drago-tools.migration-priority`
   from their `composer.json` (lower first, default 100) and then by package name,
2. inside a package, its files by name,
3. at the end, the project's own `migrations/` directory, by file name.

Before running, the order is printed:

```text
Migrations in the order they run:
   1. 001_users.sql                            (drago-ex/project-auth, priority 10)
   2. 001_roles.sql                            (drago-ex/project-permission, priority 20)
   ...
  11. 001_own_table.sql                        (project)
```

The web installer of `drago-ex/project-install` uses the same list
(`Drago\Migration\MigrationFiles::forProject()`), so the order is the same everywhere.

When a package uses tables of another package, give it a higher `migration-priority` than that package.

The priority is read from the `composer.json` of each package; it is the key Drago packages use, but
`drago-ex/project-tools` does not have to be installed. In projects outside Drago, where packages have no priority,
all packages get the default 100, so they follow by package name. To make a package of yours run earlier or later,
add the key to its `composer.json`:

```json
"extra": {
    "drago-tools": {
        "migration-priority": 30
    }
}
```

Packages released before `migration-priority` existed set the order by `commands-priority`, so it is used when
`migration-priority` is missing.

## Record Existing Migrations

When the changes of some migrations are already in the database but not recorded in the `migrations`
table (for example a database set up by an older web installer), record them without running their SQL:

```bash
php vendor/bin/migration db:baseline --packages
php vendor/bin/migration db:baseline migrations
```

Already recorded files are skipped and a recorded file that was changed is reported as an error, as with
`db:migrate`. Use it only for files whose changes really are in the database; `db:migrate` never runs a
recorded file again.

## Report of the Run

With `--packages`, `db:migrate` and `db:baseline` write what they did to `.drago-project/migration.json`
in the project, so a programmer or an agent can see the outcome later. Each run overwrites the report; the history
of executed migrations is in the `migrations` table. The report depends on the database it ran against, so add
`.drago-project/migration.json` to `.gitignore`.

```json
{
    "tool": "migration",
    "command": "migration db:migrate --packages",
    "date": "2026-10-18T14:05:12+02:00",
    "result": "ok",
    "stats": {
        "executed": 1,
        "recorded": 0,
        "skipped": 1,
        "failed": 0
    },
    "entries": [
        {
            "package": "drago-ex/project-auth",
            "file": "vendor/drago-ex/project-auth/migrations/001_users.sql",
            "action": "skipped"
        },
        {
            "package": "core",
            "file": "migrations/001_own_table.sql",
            "action": "executed"
        }
    ]
}
```

The action is `executed`, `recorded` (by `db:baseline`), `skipped` (already executed) or `failed` with a `message`.
When the run stops with an error, `result` is `error` and `message` holds the error.

Code calling `MigrationRunner` directly can pass a `MigrationReport` as the third argument of `run()` or `baseline()`.

## Export SQL Migrations (optional)

Migrations do not need to be copied into the project to run. If you want the SQL files of the packages
in your project anyway, export them:
```bash
php vendor/bin/sql-export
php vendor/bin/sql-export temp/sql
```
By default, SQL files are copied into the `migrations` directory. Existing files with the same content are
skipped, so the command can be safely run repeatedly; a copy in `migrations/` that is identical to a package
migration is left out by `--packages`, so it does not run twice.

## Packages With Migrations

Packages do not need their own migration commands or any other tool; they put SQL files into `migrations/` and set
`extra.drago-tools.migration-priority`, which decides their place in the order. Any project that installs such
packages runs them all with:

```bash
php vendor/bin/migration db:migrate --packages
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
- Package order - `--packages` runs the migrations of all packages straight from `vendor/` by `migration-priority`
- Line endings - a migration that differs only in line endings is not taken as changed
- Automatic migrations table setup on first run
- Symfony Console integration - clean CLI output

## Notes

- **MySQL or MariaDB only.** The runner uses `GET_LOCK()`, `RELEASE_LOCK()`, `INFORMATION_SCHEMA` and `DATABASE()`.
- **DDL is not rolled back.** MySQL commits statements like `CREATE`, `ALTER` and `DROP` immediately, so a
  migration that fails after such a statement stays partially applied and is not recorded. Keep one schema
  change per file, so a failed migration can be fixed and run again.
- **Where a migration is recorded.** A file inside `vendor/<vendor>/<package>/` is recorded under
  `<vendor>/<package>`, any other file under `core`, always together with its file name. A file with the same
  name and content that already ran from another place (e.g. a copy in `migrations/` exported by `sql-export`
  in an older project) is not run again, it is only recorded for this place.
- **Line endings do not count as a change.** Git may change them on checkout (e.g. on Windows); a recorded
  migration that differs only in line endings is still the same migration.
- `sql-export` skips files that already exist with the same content. A different file with the same name,
  for example from another package, is reported as a conflict and the command ends with exit code 1.
- `vendor/bin/migration` runs from the project root and loads `app/Bootstrap.php`; it calls
  `App\Bootstrap::bootConsoleApplication()`, which must return the DI container with the migration and
  console extensions registered. For non-Nette projects, a custom bootstrap script is required.
