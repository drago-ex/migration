<?php

declare(strict_types=1);

namespace Drago\Migration;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


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
		$this->addArgument('path', InputArgument::OPTIONAL, 'Path to SQL file or directory')
			->addOption('packages', null, InputOption::VALUE_NONE, "Run the migrations of all installed packages in their order, then the project's migrations/");
	}


	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$path = MigrationTarget::resolve($input, $output);
		if ($path === null) {
			return Command::FAILURE;
		}

		$report = MigrationTarget::report($input, 'db:migrate');
		try {
			$this->migrationRunner->run($path, function (string $message) use ($output) {
				$output->writeln($message);
			}, $report);

		} catch (\Throwable $e) {
			$output->writeln('<error>ERROR: ' . $e->getMessage() . '</error>');
			$report?->fail($e->getMessage());
			return Command::FAILURE;

		} finally {
			MigrationTarget::writeReport($report, $output);
		}

		return Command::SUCCESS;
	}
}
