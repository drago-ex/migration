<?php declare(strict_types=1);

namespace Drago\Migration\DI;

use Drago\Migration\BaselineCommand;
use Drago\Migration\MigrationCommand;
use Drago\Migration\MigrationRunner;
use Drago\Migration\Repository;
use Nette\DI\CompilerExtension;


/** Registers the migration commands in console mode; contributte/console finds them by type and #[AsCommand]. */
final class MigrationExtension extends CompilerExtension
{
	public function __construct(
		private readonly bool $consoleMode = false,
	) {
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
			->setFactory(MigrationCommand::class, ['@' . $this->prefix('runner')]);

		$builder->addDefinition($this->prefix('baselineCommand'))
			->setFactory(BaselineCommand::class, ['@' . $this->prefix('runner')]);
	}
}
