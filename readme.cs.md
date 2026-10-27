# Drago Migration

Jednoduchý nástroj pro příkazovou řádku na spouštění SQL migrací.
Drago Migration je malý nástroj postavený na Nette, Dibi a Symfony Console.
Spouští SQL migrace ze souborů nebo adresářů s kontrolou kontrolních součtů, v transakcích a se zámkem na úrovni databáze.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://github.com/drago-ex/migration/blob/main/license)
[![PHP version](https://badge.fury.io/ph/drago-ex%2Fmigration.svg)](https://badge.fury.io/ph/drago-ex%2Fmigration)
[![Tests](https://github.com/drago-ex/migration/actions/workflows/tests.yml/badge.svg)](https://github.com/drago-ex/migration/actions/workflows/tests.yml)
[![Coding Style](https://github.com/drago-ex/migration/actions/workflows/coding-style.yml/badge.svg)](https://github.com/drago-ex/migration/actions/workflows/coding-style.yml)

## Požadavky
- PHP >= 8.3, < 9
- Nette Framework
- Symfony Console
- dibi
- MySQL nebo MariaDB
- Composer

## Instalace
```bash
composer require drago-ex/migration
```

## Registrace rozšíření v Nette
```neon
extensions:
	migration: Drago\Migration\DI\MigrationExtension(%consoleMode%)
	console: Contributte\Console\DI\ConsoleExtension(%consoleMode%)

# Symfony Console
console:
	name: Symfony Console
	version: '1.0'
```
Parametr %consoleMode% musí být k dispozici (v nastavení Nette pro příkazovou řádku obvykle už je).

## Příklady
```bash
# Run the migrations of all installed packages in their order, then the project's migrations/
php vendor/bin/migration db:migrate --packages

# Run all migrations in a folder
php vendor/bin/migration db:migrate migrations

# Run a single migration file
php vendor/bin/migration db:migrate migrations/001_example.sql
```
Migrace, která už proběhla, se znovu nespustí.

## Pořadí migrací balíčků
S `--packages` se migrace spouštějí přímo z `vendor/` (nic se nekopíruje): balíčky seřazené podle
`migration-priority` (nižší dřív, výchozí 100) a názvu, potom vlastní `migrations/` projektu.
Balíček, který používá tabulky jiného balíčku, potřebuje vyšší prioritu:

```json
"extra": {
    "drago-tools": {
        "migration-priority": 30
    }
}
```
Balíčky Drago používají 10 pro základní tabulky (`users` z drago-ex/project-auth), 20 pro balíčky postavené na nich
(drago-ex/project-permission) a výchozí 100 pro ostatní.

## Zaznamenání existujících migrací
Zaznamená migrace jako provedené bez jejich spuštění, pro databázi, jejíž tabulky už existují:
```bash
php vendor/bin/migration db:baseline --packages
```

## Report
S `--packages` se výsledek posledního běhu zapíše do `.drago-project/migration.json`.
Pro každou databázi je jiný, přidejte ho proto do `.gitignore`.

## Nastavení databáze
Tabulka migrací se vytvoří automaticky při prvním spuštění migrací
ze SQL souboru balíčku `migrations/000_migrations.sql`:
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

## Funkce
- Kontrolní součty - odhalí upravené migrace
- Běh v transakci - neúspěšná migrace se nezaznamená (MySQL potvrzuje změny schématu hned, viz Poznámky)
- Zámek databáze - zabrání souběžným spuštěním
- Migrace balíčků - podporuje migrace z `vendor/`
- Automatické vytvoření tabulky migrací při prvním spuštění
- Integrace se Symfony Console - přehledný výstup

## Poznámky
- MySQL potvrzuje `CREATE`, `ALTER` a `DROP` okamžitě, proto dávejte do jednoho souboru jen jednu změnu schématu.
- Konce řádků se jako změna migrace nepočítají.
- Dodaný spustitelný soubor očekává strukturu projektu Nette s app/Bootstrap.php.
- Pro projekty bez Nette je potřeba vlastní spouštěcí skript.

## Export SQL migrací (volitelné)
Zkopíruje SQL soubory nainstalovaných balíčků do projektu:
```bash
php vendor/bin/sql-export
php vendor/bin/sql-export temp/sql
```
Ve výchozím stavu se SQL soubory kopírují do adresáře `migrations`. Existující soubory se stejným obsahem se přeskočí;
jiný soubor se stejným názvem se ohlásí jako CONFLICT, nezkopíruje se a skript skončí s kódem 1.
