## Drago Migration
A simple CLI tool for running SQL migrations.

Database migration tool built on **Nette Framework** using **Dibi** and **Symfony Console**.  
Allows you to run SQL migrations from files or directories with checksum validation and transaction support.

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

## Usage
```bash
php bin/console db:migrate <path>
```
Path to a single SQL file or a directory containing multiple .sql files.

## Examples
```bash
# Run all migrations in a folder
php bin/console db:migrate migrations/

# Run a single migration file
php bin/console db:migrate migrations/001_example.sql
```

## Features
- Checksum verification – detects if a migration file has been modified after execution.
- Transactional execution – ensures that each migration either fully succeeds or is rolled back.
- Locking – prevents multiple instances from running migrations concurrently.
- Package-aware – automatically detects migrations in vendor/ and handles them separately.
- Logging – outputs status messages for each migration.
