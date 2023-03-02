<?php

namespace App\Commands;

use App\Enums\CaseEnum;
use LaravelZero\Framework\Commands\Command;

abstract class BaseCommand extends Command
{
	public function namespace(): string
	{
		return $this->option('namespace') ?: '\\App\\Data';
	}

	public function getCasing(): ?CaseEnum
	{
		$casing = $this->option('casing');

		if (!$casing) {
			return null;
		}

		$validCasing = array_map(fn (CaseEnum $c): string => $c->value, CaseEnum::cases());

		if (!in_array($casing, $validCasing)) {
			$this->error('Invalid casing provided, must be one of: ' . implode(', ', $validCasing) . '.');
			exit(1);
		}

		return CaseEnum::tryFrom($casing);
	}

	public function readStdin(): string
	{
		fopen('php://stdin', 'r');
		$data = '';
		while (false !== ($line = fgets(\STDIN))) {
			$data .= $line;
		}

		return trim($data);
	}

	public function boolean(string $option): bool
	{
		if (!$this->option($option)) {
			return false;
		}

		return filter_var($this->option($option), \FILTER_VALIDATE_BOOLEAN);
	}
}
