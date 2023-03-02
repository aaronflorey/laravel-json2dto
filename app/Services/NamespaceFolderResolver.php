<?php

namespace App\Services;

use RuntimeException;
use Illuminate\Support\Str;
use Nette\PhpGenerator\PhpFile;

class NamespaceFolderResolver
{
	public function phpFileToPath(PhpFile $file): string
	{
		$namespace = array_values($file->getNamespaces())[0];
		$folder = $this->namespaceToFolder($namespace->getName());
		$className = array_values($namespace->getClasses())[0]->getName();
		$path = sprintf('%s/%s.php', $folder, $className);

		if (Str::startsWith($path, 'App/')) {
			$path = 'app' . substr($path, 3);
		}

		return $path;
	}

	public function namespaceToFolder(string $namespace): string
	{
		if (!NameValidator::validateNamespace($namespace)) {
			throw new RuntimeException('Invalid namespace provided');
		}

		return str_replace('\\', '/', $namespace);
	}

	public function namespaceSameRoot(string $a, string $b): bool
	{
		return !empty($a) && !empty($b) && explode('\\', $a)[0] === explode('\\', $b)[0];
	}
}
