<?php

/**
 * Test: Drago\Migration\MigrationFiles
 */

declare(strict_types=1);

use Drago\Migration\MigrationFiles;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** @param list<string> $files */
function createDir(array $files): string
{
	$dir = __DIR__ . '/../tmp/files-' . getmypid() . '-' . bin2hex(random_bytes(4));
	@mkdir($dir, 0o777, true);
	Tester\Helpers::purge($dir);
	foreach ($files as $file) {
		file_put_contents("$dir/$file", "SELECT 1;\n");
	}

	return $dir;
}


/** @return list<string> */
function names(string $dir): array
{
	return array_map('basename', MigrationFiles::list($dir));
}


test('Without the order file, migrations are sorted by name', function () {
	$dir = createDir(['002_b.sql', '001_a.sql', '010_c.sql']);
	Assert::same(['001_a.sql', '002_b.sql', '010_c.sql'], names($dir));
});


test('The order file decides; unlisted files follow by name, missing ones are ignored', function () {
	$dir = createDir(['001_users.sql', '001_roles.sql', '002_users_roles.sql', '001_own.sql']);
	MigrationFiles::writeOrder($dir, ['001_users.sql', 'removed.sql', '001_roles.sql', '002_users_roles.sql']);

	Assert::same(['001_users.sql', '001_roles.sql', '002_users_roles.sql', '001_own.sql'], names($dir));
});


test('An invalid order file is reported instead of running migrations in a wrong order', function () {
	$dir = createDir(['001_a.sql']);
	file_put_contents($dir . '/' . MigrationFiles::OrderFile, '{"order": []}');

	Assert::exception(
		fn() => MigrationFiles::list($dir),
		RuntimeException::class,
		'Invalid db-migration-order.json in %a%',
	);
});
