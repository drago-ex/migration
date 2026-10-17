# Agent Guide

Use this file as package-specific guidance when working in this repository. Keep changes aligned with the existing public API, project conventions, and supported PHP range.

## Package Overview
- **Package**: `drago-ex/migration`
- **Type**: library
- **Purpose**: A simple CLI tool for running SQL migrations.
- **PHP**: >=8.3 <9
- **Runtime dependencies**: composer-runtime-api (^2.0), contributte/console (^0.10), dibi/dibi (^5.0)
- **License**: MIT

## Repository Layout
- `bin/`: command-line entry points.
- `migrations/`: database migrations.
- `src/`: package source code.
- `tests/`: automated tests.
- `.github/`: CI workflows; keep their PHP versions and commands consistent with Composer and the package scripts.
- `composer.json`: package metadata, dependency constraints, autoloading, and available Composer scripts.

## Key Parts
- `MigrationRunner` runs a file, a directory (by file name) or a list of files (in its order) under a `GET_LOCK()` lock, records checksums per package (`vendor/<vendor>/<package>` or `core`) and file name, and stops on a changed file. MySQL and MariaDB only.
- `MigrationFiles::forProject()` builds the list for `db:migrate --packages` / `db:baseline --packages`, the web installer (`drago-ex/project-install`) and `drago-setup` (`drago-ex/project-tools`): package migrations straight from `vendor/` ordered by `extra.drago-tools.commands-priority` (default 100) and package name, files by name, then the project's `migrations/` (copies identical to a package migration are left out). Nothing is copied.
- Line endings never count as a change: checksums are compared with LF and CRLF variants.
- The same file with the same checksum recorded under another package (`vendor/` vs. `core`) counts as executed and is only recorded for the current location.
- A failure to release the lock must not hide the migration error. MySQL commits DDL statements immediately, so transactions do not undo them; keep one schema change per file.
- `bin/sql-export` is optional: it copies package migrations into the project; a different file with the same name (ignoring line endings) is a conflict (exit code 1).
- Unit tests use a fake `Repository` (`tests/Migration/MigrationRunner.phpt`); `tests/Migration/MigrationFiles.phpt` fakes installed packages with `InstalledVersions::reload()`.

## Dependencies

Composer constraints describe the package contract. Before changing integration behavior, inspect the source and the actual call sites in this package; do not infer runtime usage solely from dependency declarations.
Development dependencies include nette/bootstrap (^3.2), nette/tester (^2.5), tracy/tracy (^2.10), phpstan/phpstan-nette (^2.0).

## Essential Commands

```bash
composer tester                 # tester tests -s
composer phpstan                # phpstan analyse
```

## Conventions & Rules

- Preserve backward compatibility unless the task explicitly permits a breaking change. Check callers and public signatures before changing behavior.
- Follow the existing PHP style: strict types, explicit parameter and return types, tabs for indentation, and single-quoted strings where practical. Match nearby code when a convention is unclear.
- Keep package-specific configuration, resources, and migrations in their established directories. Do not edit generated or consuming-project files without confirming they are part of this repository’s intended output.
- Add or update focused tests for behavioral changes when this package has a runnable test setup. Do not invent test commands; use only the scripts and configuration present here.
- Update README examples and Composer metadata when a public API or supported requirement changes.
