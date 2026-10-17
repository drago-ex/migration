<?php

declare(strict_types=1);

namespace Drago\Migration;


/**
 * Lists the migrations of a directory in the order they must run.
 *
 * sql-export copies the migrations of all packages into one directory and writes db-migration-order.json,
 * where packages follow their commands-priority (as drago-setup runs them). Files that are not listed
 * there (e.g. the project's own migrations) come after them, sorted by name. Without the file,
 * all migrations are sorted by name.
 */
final class MigrationFiles
{
	public const string OrderFile = 'db-migration-order.json';


	/**
	 * @return list<string> absolute paths
	 */
	public static function list(string $dir): array
	{
		$dir = rtrim($dir, '/\\');
		$files = glob($dir . '/*.sql');
		if ($files === false) {
			throw new \RuntimeException(sprintf('Unable to read directory "%s".', $dir));
		}

		$names = array_map('basename', $files);
		sort($names);

		$ordered = [];
		foreach (self::readOrder($dir) as $name) {
			if (in_array($name, $names, true) && !in_array($name, $ordered, true)) {
				$ordered[] = $name;
			}
		}

		$names = [...$ordered, ...array_values(array_diff($names, $ordered))];
		return array_map(fn(string $name): string => $dir . '/' . $name, $names);
	}


	/**
	 * @param list<string> $names
	 */
	public static function writeOrder(string $dir, array $names): void
	{
		$json = json_encode(['migrations' => $names], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($json === false || file_put_contents(rtrim($dir, '/\\') . '/' . self::OrderFile, $json . "\n") === false) {
			throw new \RuntimeException(sprintf('Unable to write %s into "%s".', self::OrderFile, $dir));
		}
	}


	/**
	 * @return list<string>
	 */
	private static function readOrder(string $dir): array
	{
		$file = $dir . '/' . self::OrderFile;
		if (!is_file($file)) {
			return [];
		}

		$data = json_decode((string) file_get_contents($file), true);
		$names = is_array($data) ? ($data['migrations'] ?? null) : null;
		if (!is_array($names)) {
			throw new \RuntimeException(sprintf('Invalid %s in "%s".', self::OrderFile, $dir));
		}

		return array_values(array_filter($names, 'is_string'));
	}
}
