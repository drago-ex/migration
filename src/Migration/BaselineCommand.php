<?php

declare(strict_types=1);

namespace Drago\Migration;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


#[AsCommand(name: 'db:baseline', description: 'Record SQL migrations as executed without running them')]
final class BaselineCommand extends Command
{
	public function __construct(
		private readonly MigrationRunner $migrationRunner,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addArgument('path', InputArgument::OPTIONAL, 'Path to SQL file or directory')
			->addOption('packages', null, InputOption::VALUE_NONE, "Record the migrations of all installed packages and the project's migrations/")
			->setHelp('Use it only for migrations whose changes are already in the database, e.g. a database set up by an older web installer.');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$path = MigrationTarget::resolve($input, $output);
		if ($path === null) {
			return Command::FAILURE;
		}

		try {
			$this->migrationRunner->baseline($path, function (string $message) use ($output) {
				$output->writeln($message);
			});

		} catch (\Throwable $e) {
			$output->writeln('<error>ERROR: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}
}
