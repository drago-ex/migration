<?php

/**
 * Drago Extension
 * Package built on Nette Framework
 */

declare(strict_types=1);

namespace Drago\DI;

use Drago\Migration\MigrationCommand;
use Drago\Migration\MigrationRunner;
use Drago\Migration\Repository;
use Nette\DI\CompilerExtension;


final class MigrationExtension extends CompilerExtension
{
	public function loadConfiguration(): void
	{
		$builder = $this->getContainerBuilder();

		// Repository
		$builder->addDefinition($this->prefix('repository'))
			->setFactory(Repository::class);

		// MigrationRunner
		$builder->addDefinition($this->prefix('runner'))
			->setFactory(MigrationRunner::class, [$this->prefix('@repository')]);

		// MigrationCommand
		$builder->addDefinition($this->prefix('command'))
			->setFactory(MigrationCommand::class, [$this->prefix('@runner')])
			->addTag('console.command');
	}
}
