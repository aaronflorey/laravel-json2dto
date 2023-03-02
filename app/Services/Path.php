<?php

namespace App\Services;

use Illuminate\Support\Str;

class Path
{
	public function relativePath(string $from, string $to): string
	{
		$fromPath = $this->absolutePath($from);
		$toPath = $this->absolutePath($to);

		$fromPathParts = explode(\DIRECTORY_SEPARATOR, rtrim((string) $fromPath, \DIRECTORY_SEPARATOR));
		$toPathParts = explode(\DIRECTORY_SEPARATOR, rtrim((string) $toPath, \DIRECTORY_SEPARATOR));

		$fromPathPartsCount = count($fromPathParts);
		$toPathPartsCount = count($toPathParts);

		while ($fromPathPartsCount && $toPathPartsCount && ($fromPathParts[0] == $toPathParts[0])) {
			array_shift($fromPathParts);
			array_shift($toPathParts);

			$fromPathPartsCount = count($fromPathParts);
			$toPathPartsCount = count($toPathParts);
		}

		return str_pad('', count($fromPathParts) * 3, '..' . \DIRECTORY_SEPARATOR) . implode(\DIRECTORY_SEPARATOR, $toPathParts);
	}

	public function absolutePath(string $path): string
	{
		$isEmptyPath = (strlen($path) == 0);
		$isRelativePath = ($path[0] != '/');
		$isWindowsPath = !(!Str::contains($path, ':'));

		if (($isEmptyPath || $isRelativePath) && !$isWindowsPath) {
			$path = getcwd() . \DIRECTORY_SEPARATOR . $path;
		}

		// resolve path parts (single dot, double dot and double delimiters)
		$path = str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
		$pathParts = array_filter(explode(\DIRECTORY_SEPARATOR, $path), 'strlen');
		$absolutePathParts = [];
		foreach ($pathParts as $part) {
			if ($part == '.') {
				continue;
			}

			if ($part == '..') {
				array_pop($absolutePathParts);
			} else {
				$absolutePathParts[] = $part;
			}
		}

		$path = implode(\DIRECTORY_SEPARATOR, $absolutePathParts);

		// resolve any symlinks
		if (file_exists($path) && linkinfo($path) > 0) {
			$path = readlink($path);
		}

		// put initial separator that could have been lost
		return (!$isWindowsPath ? '/' . $path : $path);
	}
}
