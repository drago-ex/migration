<?php declare(strict_types=1);

namespace Drago\Migration;

use function strlen;
use const JSON_PRETTY_PRINT, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE;


/**
 * Record of one migration run, written to .drago-project/migration.json in the project, so a programmer
 * or an agent can see what ran, what was skipped and what failed. Each run overwrites the previous report;
 * the history of executed migrations is in the migrations table.
 */
final class MigrationReport
{
	/** Path of the report, relative to the project root. */
	public const string File = '.drago-project/migration.json';

	/** @var list<array{package: string, file: string, action: string, message?: string}> */
	private array $entries = [];

	private ?string $error = null;


	public function __construct(
		private readonly string $projectRoot,
		private readonly string $command,
	) {
	}


	/**
	 * @param 'executed'|'recorded'|'skipped'|'failed' $action
	 */
	public function add(string $package, string $file, string $action, ?string $message = null): void
	{
		$entry = ['package' => $package, 'file' => $this->relativePath($file), 'action' => $action];
		if ($message !== null) {
			$entry['message'] = $message;
		}

		$this->entries[] = $entry;
	}


	/** The run ended with an error, e.g. the migration lock could not be acquired. */
	public function fail(string $message): void
	{
		$this->error = $message;
	}


	/** @return list<array{package: string, file: string, action: string, message?: string}> */
	public function getEntries(): array
	{
		return $this->entries;
	}


	/** @return array<string, mixed> */
	public function toArray(): array
	{
		$stats = ['executed' => 0, 'recorded' => 0, 'skipped' => 0, 'failed' => 0];
		foreach ($this->entries as $entry) {
			$stats[$entry['action']]++;
		}

		$data = [
			'tool' => 'migration',
			'command' => $this->command,
			'date' => date('c'),
			'result' => $this->error === null && $stats['failed'] === 0 ? 'ok' : 'error',
		];
		if ($this->error !== null) {
			$data['message'] = $this->error;
		}

		return $data + ['stats' => $stats, 'entries' => $this->entries];
	}


	/** @return bool false when the report cannot be written */
	public function write(): bool
	{
		$path = $this->projectRoot . '/' . self::File;
		$json = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if ($json === false || (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0o777, true) && !is_dir(dirname($path)))) { // @ directory may already exist
			return false;
		}

		return file_put_contents($path, $json . "\n") !== false;
	}


	private function relativePath(string $path): string
	{
		$root = rtrim(str_replace('\\', '/', $this->projectRoot), '/') . '/';
		$path = str_replace('\\', '/', $path);
		return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
	}
}
