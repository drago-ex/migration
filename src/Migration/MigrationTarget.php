<?php

declare(strict_types=1);

namespace Drago\Migration;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/**
 * What the migration commands work with: the path argument, or with --packages the migrations of all installed
 * packages in their order followed by the project's own (the order is printed first).
 * @internal
 */
final class MigrationTarget
{
	/**
	 * @return string|list<string>|null null when there is nothing to run
	 */
	public static function resolve(InputInterface $input, OutputInterface $output): string|array|null
	{
		$path = $input->getArgument('path');
		if (!$input->getOption('packages')) {
			if (!is_string($path) || $path === '') {
				$output->writeln('<error>ERROR: Pass a path to an SQL file or directory, or use --packages.</error>');
				return null;
			}

			return $path;
		}

		if (is_string($path) && $path !== '') {
			$output->writeln('<error>ERROR: Use either a path or --packages, not both.</error>');
			return null;
		}

		$migrations = MigrationFiles::forProject((string) getcwd());
		if ($migrations === []) {
			$output->writeln('<comment>No migrations found in the installed packages or in migrations/.</comment>');
			return [];
		}

		$output->writeln('<info>Migrations in the order they run:</info>');
		foreach (MigrationFiles::describe($migrations) as $line) {
			$output->writeln($line);
		}

		$output->writeln('');
		return array_column($migrations, 'file');
	}
}
