<?php

declare(strict_types=1);

namespace Drago\Migration\DI;

use Drago\Migration\MigrationCommand;
use Drago\Migration\MigrationRunner;
use Drago\Migration\Repository;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Symfony\Component\Console\Command\Command;


/** DI extension for database migrations integration. */
final class MigrationExtension extends CompilerExtension
{
	private bool $consoleMode;


	public function __construct(bool $consoleMode = false)
	{
		$this->consoleMode = $consoleMode;
	}


	public function loadConfiguration(): void
	{
		if (!$this->consoleMode) {
			return;
		}

		$builder = $this->getContainerBuilder();
		$builder->addDefinition($this->prefix('repository'))
			->setFactory(Repository::class);

		$builder->addDefinition($this->prefix('runner'))
			->setFactory(MigrationRunner::class, ['@' . $this->prefix('repository')]);

		$builder->addDefinition($this->prefix('command'))
			->setFactory(MigrationCommand::class, ['@' . $this->prefix('runner')])
			->addTag('console.command');

		$this->compiler->addExportedType(MigrationCommand::class);
	}


	public function beforeCompile(): void
	{
		if (!$this->consoleMode) {
			return;
		}

		$builder = $this->getContainerBuilder();
		$commands = $builder->findByType(Command::class);
		foreach ($commands as $serviceDef) {
			assert($serviceDef instanceof ServiceDefinition);
			$serviceDef->addTag('console.command');
		}
	}
}
