<?php

/**
 * Drago Extension
 * Package built on Nette Framework
 */

declare(strict_types=1);

namespace Drago\Migration\DI;

use Drago\Migration\MigrationCommand;
use Drago\Migration\MigrationRunner;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\ServiceDefinition;
use Symfony\Component\Console\Command\Command;


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
		$builder->addDefinition($this->prefix('runner'))
			->setFactory(MigrationRunner::class);

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
		foreach ($commands as $serviceName => $serviceDef) {
			assert($serviceDef instanceof ServiceDefinition);
			$serviceDef->addTag('console.command');
		}
	}
}
