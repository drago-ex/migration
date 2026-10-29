<?php declare(strict_types=1);

/**
 * Test: Drago\Migration\MigrationFiles
 */

use Composer\InstalledVersions;
use Drago\Migration\MigrationFiles;
use Tester\Assert;

require __DIR__ . '/../bootstrap.php';


/** @param array<string, string> $files path => content */
function createTree(array $files): string
{
	$root = __DIR__ . '/../tmp/files-' . getmypid() . '-' . bin2hex(random_bytes(4));
	foreach ($files as $path => $content) {
		@mkdir(dirname("$root/$path"), 0o777, true); // @ directory may already exist
		file_put_contents("$root/$path", $content);
	}

	return $root;
}


/** @param array<string, string> $packages name => install path */
function fakeInstalled(string $root, array $packages): void
{
	$package = static fn(string $path): array => [
		'pretty_version' => '1.0.0',
		'version' => '1.0.0.0',
		'reference' => null,
		'type' => 'library',
		'install_path' => $path,
		'aliases' => [],
		'dev_requirement' => false,
	];

	$versions = ['acme/project' => $package($root)];
	foreach ($packages as $name => $path) {
		$versions[$name] = $package($path);
	}

	InstalledVersions::reload([
		'root' => ['name' => 'acme/project', 'install_path' => $root, 'dev' => true] + $package($root),
		'versions' => $versions,
	]);
}


/**
 * Only the faked packages; Composer also reports the packages really installed for this repository.
 * @return list<string>
 */
function plan(string $root): array
{
	$migrations = array_filter(
		MigrationFiles::forProject($root),
		fn(array $m): bool => $m['package'] === 'core' || str_starts_with($m['package'], 'acme/'),
	);

	return array_values(array_map(fn(array $m): string => $m['package'] . ':' . basename($m['file']), $migrations));
}


test('A directory lists its migrations by name', function () {
	$root = createTree(['m/002_b.sql' => '', 'm/001_a.sql' => '', 'm/010_c.sql' => '', 'm/notes.txt' => '']);
	Assert::same(['001_a.sql', '002_b.sql', '010_c.sql'], array_map('basename', MigrationFiles::list("$root/m")));
});


test('Packages follow migration-priority, then name; the project\'s own migrations come last', function () {
	$root = createTree([
		'vendor/acme/permission/composer.json' => '{"extra": {"drago-tools": {"migration-priority": 20, "commands-priority": 5}}}',
		'vendor/acme/permission/migrations/001_roles.sql' => 'roles',
		'vendor/acme/permission/migrations/002_users_roles.sql' => 'users_roles',
		'vendor/acme/auth/composer.json' => '{"extra": {"drago-tools": {"migration-priority": 10}}}',
		'vendor/acme/auth/migrations/001_users.sql' => 'users',
		'vendor/acme/settings/composer.json' => '{}',
		'vendor/acme/settings/migrations/001_settings.sql' => 'settings',
		'vendor/acme/library/composer.json' => '{}',
		'migrations/001_own.sql' => 'own',
	]);
	fakeInstalled($root, [
		'acme/permission' => "$root/vendor/acme/permission",
		'acme/settings' => "$root/vendor/acme/settings",
		'acme/auth' => "$root/vendor/acme/auth",
		'acme/library' => "$root/vendor/acme/library",
	]);

	Assert::same([
		'acme/auth:001_users.sql',
		'acme/permission:001_roles.sql',
		'acme/permission:002_users_roles.sql',
		'acme/settings:001_settings.sql',
		'core:001_own.sql',
	], plan($root));

	Assert::same(
		'   1. 001_users.sql                            (acme/auth, priority 10)',
		MigrationFiles::describe(MigrationFiles::forProject($root))[0],
	);
});


test('Packages released before migration-priority keep their order by commands-priority', function () {
	$root = createTree([
		'vendor/acme/old/composer.json' => '{"extra": {"drago-tools": {"commands-priority": 10}}}',
		'vendor/acme/new/composer.json' => '{"extra": {"drago-tools": {"migration-priority": 20}}}',
	]);

	Assert::same(10, MigrationFiles::packagePriority("$root/vendor/acme/old"));
	Assert::same(20, MigrationFiles::packagePriority("$root/vendor/acme/new"));
	Assert::same(MigrationFiles::DefaultPriority, MigrationFiles::packagePriority("$root/vendor/acme/missing"));
});


test('Copies of package migrations in migrations/ are left out, also with other line endings', function () {
	$root = createTree([
		'vendor/acme/auth/composer.json' => '{}',
		'vendor/acme/auth/migrations/001_users.sql' => "CREATE TABLE users;\n",
		'migrations/001_users.sql' => "CREATE TABLE users;\r\n",
		'migrations/002_own.sql' => 'own',
	]);
	fakeInstalled($root, ['acme/auth' => "$root/vendor/acme/auth"]);

	Assert::same(['acme/auth:001_users.sql', 'core:002_own.sql'], plan($root));
});


test('The migrations table of this package is left out, the runner creates it first', function () {
	$root = createTree(['vendor/acme/users/migrations/001_users.sql' => '']);
	fakeInstalled($root, [
		'acme/migration' => dirname(__DIR__, 2),
		'acme/users' => "$root/vendor/acme/users",
	]);

	Assert::same(['acme/users:001_users.sql'], plan($root));
});
