<?php

declare(strict_types=1);

namespace Drago\Migration;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


/** Console command for running database migrations. */
#[AsCommand(name: 'db:migrate', description: 'Run SQL database migrations')]
final class MigrationCommand extends Command
{
	public function __construct(
		private readonly MigrationRunner $migrationRunner,
	) {
		parent::__construct();
	}


	protected function configure(): void
	{
		$this->addArgument('path', InputArgument::REQUIRED, 'Path to SQL file or directory');
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$path = $input->getArgument('path');

		try {
			$this->migrationRunner->run($path, function (string $message) use ($output) {
				$output->writeln($message);
			});

		} catch (\Throwable $e) {
			$output->writeln('❌ <error>' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}
}
