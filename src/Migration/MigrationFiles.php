<?php

declare(strict_types=1);

namespace Drago\Migration;

use Composer\InstalledVersions;


/**
 * Finds migration files and the order they must run in.
 *
 * Migrations of different packages often depend on each other (a table of one package refers to a table
 * of another), so the packages follow their extra.drago-tools.commands-priority from composer.json
 * (lower first, default 100, then by name). They run straight from vendor/, nothing is copied.
 */
final class MigrationFiles
{
	public const int DefaultPriority = 100;

	/** Directory of the project's own migrations, relative to the project root. */
	public const string ProjectDir = 'migrations';


	/**
	 * The .sql files of a directory, sorted by name.
	 * @return list<string> absolute paths
	 */
	public static function list(string $dir): array
	{
		$files = glob(rtrim($dir, '/\\') . '/*.sql');
		if ($files === false) {
			throw new \RuntimeException(sprintf('Unable to read directory "%s".', $dir));
		}

		sort($files);
		return $files;
	}


	/**
	 * Migrations of all installed packages in their order, followed by the project's own migrations
	 * (the migrations/ directory), sorted by name.
	 * @return list<array{file: string, package: string, priority: ?int}> package is 'core' for the project's own
	 */
	public static function forProject(string $projectRoot): array
	{
		$packages = [];
		$root = realpath($projectRoot);
		foreach (InstalledVersions::getInstalledPackages() as $name) {
			$path = InstalledVersions::getInstallPath($name);
			$path = $path !== null ? realpath($path) : false;

			// The project itself is the root package; its migrations/ are added at the end.
			if ($path === false || $path === $root) {
				continue;
			}

			$files = [];
			foreach (['migrations', 'migration'] as $dir) {
				if (is_dir("$path/$dir")) {
					$files = [...$files, ...self::list("$path/$dir")];
				}
			}

			if ($files !== []) {
				$packages[] = ['name' => $name, 'priority' => self::packagePriority($path), 'files' => $files];
			}
		}

		usort($packages, fn(array $a, array $b): int => [$a['priority'], $a['name']] <=> [$b['priority'], $b['name']]);

		$migrations = [];
		$packageContents = [];
		foreach ($packages as $package) {
			foreach ($package['files'] as $file) {
				$migrations[] = ['file' => $file, 'package' => $package['name'], 'priority' => $package['priority']];
				$packageContents[basename($file)] = self::content($file);
			}
		}

		$projectDir = ($root !== false ? $root : rtrim($projectRoot, '/\\')) . '/' . self::ProjectDir;
		foreach (is_dir($projectDir) ? self::list($projectDir) : [] as $file) {
			// Copies of package migrations exported by sql-export into older projects already run from vendor/.
			if (($packageContents[basename($file)] ?? null) === self::content($file)) {
				continue;
			}

			$migrations[] = ['file' => $file, 'package' => 'core', 'priority' => null];
		}

		return $migrations;
	}


	/**
	 * Lines describing the order, for the console.
	 * @param list<array{file: string, package: string, priority: ?int}> $migrations
	 * @return list<string>
	 */
	public static function describe(array $migrations): array
	{
		$lines = [];
		foreach ($migrations as $i => $migration) {
			$source = $migration['priority'] === null
				? 'project'
				: $migration['package'] . ', priority ' . $migration['priority'];
			$lines[] = sprintf('%4d. %-40s (%s)', $i + 1, basename($migration['file']), $source);
		}

		return $lines;
	}


	/**
	 * extra.drago-tools.commands-priority from the composer.json of a package; drago-ex/project-tools is not needed.
	 */
	public static function packagePriority(string $packagePath): int
	{
		$composerFile = $packagePath . '/composer.json';
		$composer = is_file($composerFile) ? json_decode((string) file_get_contents($composerFile), true) : null;
		$priority = is_array($composer) ? ($composer['extra']['drago-tools']['commands-priority'] ?? null) : null;
		return is_int($priority) ? $priority : self::DefaultPriority;
	}


	/** Content regardless of line endings, which Git may change on checkout. */
	private static function content(string $file): string
	{
		return str_replace("\r\n", "\n", (string) file_get_contents($file));
	}
}
