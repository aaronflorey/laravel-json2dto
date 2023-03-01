<?php

namespace App\Services;

use RuntimeException;

class NamespaceFolderResolver
{
	/** @var null|array */
	private $composerConfig;

	public function __construct(?array $composerConfig = null)
	{
		$this->composerConfig = $composerConfig;
	}

	public function namespaceToFolder(string $namespace): string
	{
		if (!NameValidator::validateNamespace($namespace)) {
			throw new RuntimeException('Invalid namespace provided');
		}

		if ($this->composerConfig === null) {
			return str_replace('\\', '/', $namespace);
		}

		foreach ($this->composerConfig['autoload']['psr-4'] ?? [] as $autoloadNamespace => $autoloadFolder) {
			if (!$this->namespaceSameRoot($namespace, $autoloadNamespace)) {
				continue;
			}

			$subNamespace = str_replace($autoloadNamespace, '', $namespace);

			return $autoloadFolder . str_replace('\\', '/', $subNamespace);
		}

		return str_replace('\\', '/', $namespace);
	}

	public function namespaceSameRoot(string $a, string $b): bool
	{
		return !empty($a) && !empty($b) && explode('\\', $a)[0] === explode('\\', $b)[0];
	}

	private function getComposerConfig(): ?array
	{
		if (!file_exists(getcwd() . '/composer.json')) {
			return null;
		}

		return json_decode(file_get_contents(getcwd() . '/composer.json'), true);
	}
}
