<?php

namespace App\Commands;

use App\Services\Path;
use Illuminate\Support\Str;
use App\Services\JsonGenerator;
use App\Services\NameValidator;
use Nette\PhpGenerator\PsrPrinter;
use Illuminate\Support\Facades\File;
use App\Services\NamespaceFolderResolver;

class GenerateJsonCommand extends BaseCommand
{
	protected $signature = 'generate:json
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
        {--casing= : The casing to use for the properties. Defaults to no change.}
        {--filename= : The filename for the root file, defaults to Root. }
        {--dates : Attempt to cast dates to Carbon. }
        {--json-output : Output the classes as JSON. }
    ';
	protected $description = 'Generate DTOs from JSON.';

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

		if ($output === '' || $output === '0') {
			$this->error('The output directory provided is not valid');
			return 1;
		}

		File::ensureDirectoryExists($output);

		$namespaceResolver = new NamespaceFolderResolver();
		$printer = new PsrPrinter();

		$service = new JsonGenerator(
			$json,
			$this->namespace(),
			$this->option('filename') ?: 'Root',
			$this->option('setters') || $this->option('all'),
			$this->option('getters') || $this->option('all'),
			$this->getCasing(),
			$this->boolean('dates'),
		);

		$service->run();
		$jsonOutput = [];

		foreach ($service->files() as $classFile) {
			$path = $namespaceResolver->phpFileToPath($classFile);

			if ($this->boolean('dry')) {
				$this->info('-------------------------------------------------------------------');
				$this->info('File: ' . $path);
				$this->info($printer->printFile($classFile));
				$this->info('-------------------------------------------------------------------');

				continue;
			}

			$absPath = $pathHelper->absolutePath($output . \DIRECTORY_SEPARATOR . $path);
			$basePath = dirname((string) $absPath);
			File::ensureDirectoryExists($basePath);

			if ($this->boolean('json-output')) {
				$jsonOutput[] = [
					'class' => $printer->printFile($classFile),
					'path'  => $path,
				];

				continue;
			}

			if (File::exists($absPath) && !$this->boolean('force')) {
				$this->warn(sprintf('File already exists [%s], use --force to overwrite', $path));
				continue;
			}

			File::put($absPath, $printer->printFile($classFile));
			$this->info('Wrote: ' . $path);
		}

		if ($this->boolean('json-output') && !$this->boolean('dry')) {
			$this->info(json_encode($jsonOutput, \JSON_PRETTY_PRINT));
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
}
