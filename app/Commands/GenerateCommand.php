<?php

namespace App\Commands;

use App\Services\Path;
use App\Enums\CaseEnum;
use Illuminate\Support\Str;
use App\Services\DtoGenerator;
use App\Services\NameValidator;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use App\Services\NamespaceFolderResolver;
use LaravelZero\Framework\Commands\Command;

class GenerateCommand extends Command
{
	protected $signature = 'generate
        {--namespace= : The namespace the classes should be generated in. Defaults to App\Data}
        {--json-file= : The file location of the json}
        {--json-stdin : Read json from stdin}
        {--json= : The json to use}
        {--output= : The output directory}
        {--dry : Print instead of write to file}
        {--getters : Generate getters}
        {--setters : Generate setters}
        {--all : Generate getters and setters}
        {--force : Overwrite existing files}
        {--casing= : The casing to use for the properties. Defaults to pascal.}
    ';
	protected $description = 'Generate DTOs from JSON. You may echo the json into this command or use --json= to point to a file.';

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle(): int
	{
		if (!NameValidator::validateNamespace($this->namespace())) {
			$this->error('Invalid namespace provided');

			return 1;
		}

		if ($json = $this->option('json')) {
			if (!$this->checkJson($json)) {
				return 1;
			}

			return $this->generate($json);
		}

		if ($jsonFile = $this->option('json-file')) {
			$jsonFile = realpath($jsonFile);
			if (!File::exists($jsonFile)) {
				$this->error('The json file provided does not exist');
				return 1;
			}

			$json = File::get($jsonFile);
			if (!$this->checkJson($json)) {
				return 1;
			}

			return $this->generate($json);
		}

		if ($this->option('json-stdin')) {
			$json = $this->readStdin();
			if (!$this->checkJson($json)) {
				return 1;
			}

			return $this->generate($json);
		}

		$response = $this->ask('Please paste the JSON you want to parse');
		if (!$this->checkJson($response)) {
			return 1;
		}

		return $this->generate($response);
	}

	private function generate(string $json): int
	{
		$pathHelper = new Path();
		$output = $pathHelper->absolutePath($this->option('output') ?: './');

		if (!$output) {
			$this->error('The output directory provided is not valid');
			return 1;
		}

		File::ensureDirectoryExists($output);

		$dryRun = filter_var($this->option('dry'), \FILTER_VALIDATE_BOOLEAN);

		$namespaceResolver = new NamespaceFolderResolver(null);

		$service = new DtoGenerator(
			$json,
			$this->namespace(),
			$this->option('setters') || $this->option('all'),
			$this->option('getters') || $this->option('all'),
			$this->getCasing()
		);

		$service->run();

		foreach ($service->files($namespaceResolver) as $class) {
			if ($dryRun) {
				$this->info('-------------------------------------------------------------------');
				$this->info($class['path']);
				$this->info($class['class']);
				$this->info('-------------------------------------------------------------------');

				continue;
			}

			$path = $class['path'];
			$absPath = $pathHelper->absolutePath($output . \DIRECTORY_SEPARATOR . $path);
			$basePath = dirname($absPath);
			File::ensureDirectoryExists($basePath);

			if (File::exists($absPath) && !$this->option('force')) {
				$this->warn(sprintf('File already exists [%s], use --force to overwrite', $path));
				continue;
			}

			File::put($absPath, $class['class']);
			$this->formatFile($absPath);
			$this->info('Wrote: ' . $path);
		}

		return 0;
	}

	private function checkJson(string $json): bool
	{
		$json = trim($json);

		if (!Str::startsWith($json, ['{', '['])) {
			$this->error('The json provided is not valid');
			return false;
		}

		if (!Str::isJson($json)) {
			$this->error('The json provided is not valid');
			return false;
		}

		return true;
	}

	private function readStdin(): string
	{
		fopen('php://stdin', 'r');
		$json = '';
		while (false !== ($line = fgets(\STDIN))) {
			$json .= $line;
		}

		return trim($json);
	}

	private function isValidOutputDirectory(string $path): bool
	{
		return true;
	}

	private function namespace(): string
	{
		return $this->option('namespace') ?: '\\App\\Data';
	}

	private function getCasing()
	{
		$casing = $this->option('casing') ?: 'camel';
		$validCasing = array_map(fn (CaseEnum $c) => $c->value, CaseEnum::cases());

		if (!in_array($casing, $validCasing)) {
			$this->error('Invalid casing provided, must be one of: ' . implode(', ', $validCasing) . '.');
			exit(1);
		}

		return CaseEnum::tryFrom($casing);
	}

	private function formatFile(string $path): void
	{
		$process = new Process(['ecs', 'check', '--fix', $path]);
		$process->run();
	}
}
