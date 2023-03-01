<?php

namespace App\Services;

use App\Enums\CaseEnum;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpNamespace;
use Spatie\LaravelData\Attributes\MapName;

class PropertyGenerator
{
	public function __construct(
		private bool $withSetters = false,
		private bool $withGetters = false,
		private CaseEnum $withCasing = CaseEnum::PASCAL
	) {
	}

	public function addProperty(PhpNamespace $namespace, ClassType $class, int|string $key, ?string $type)
	{
		$type = $type ?: 'mixed';

		$propertyName = match ($this->withCasing) {
			CaseEnum::CAMEL  => Str::camel($key),
			CaseEnum::SNAKE  => Str::snake($key),
			CaseEnum::KEBAB  => Str::kebab($key),
			CaseEnum::PASCAL => Str::studly($key),
			default          => $key
		};

		$property = $class->addProperty($propertyName)
			->setVisibility('public')
			->setType($type)
			->setNullable(true)
			->setValue(null);

		if ($propertyName !== $key) {
			$property->addAttribute(
				MapName::class,
				[
					'input' => $key,
				]
			);
		}

		$this->addSetter($class, $propertyName, $type);
		$this->addGetter($class, $propertyName, $type);

		return $property;
	}

	public function getType(mixed $value): ?string
	{
		if (is_null($value)) {
			return null;
		}

		if (is_string($value)) {
			return 'string';
		}

		if (is_object($value)) {
			return 'object';
		}

		if (is_array($value)) {
			return 'array';
		}

		if (is_bool($value)) {
			return 'bool';
		}

		if (is_int($value)) {
			return 'int';
		}

		if (is_float($value)) {
			return 'float';
		}

		ray('invalid_type', $value);
		return null;
	}

	public function getTypes(array $value): array
	{
		$types = [];

		foreach ($value as $item) {
			$types[] = $this->getType($item);
		}

		return array_unique($types);
	}

	public function addSetter(ClassType $class, int|string $key, ?string $type): void
	{
		if (!$this->withSetters) {
			return;
		}

		$method = $class->addMethod('set' . Str::studly($key))
			->setReturnType('self')
			->addBody(sprintf('$this->%s = $%s;', $key, $key))
			->addBody('return $this;')
			->setReturnNullable(true)
			->setReturnReference(false)
			->setVisibility('public');

		$method->addParameter($key)
			->setType($type)
			->setNullable(true);
	}

	public function addGetter(ClassType $class, int|string $key, ?string $type): void
	{
		if (!$this->withGetters) {
			return;
		}

		$class->addMethod('get' . Str::studly($key))
			->setReturnType($type)
			->setReturnNullable(true)
			->setReturnReference(false)
			->setVisibility('public')
			->setBody(sprintf('return $this->%s;', $key));
	}
}
