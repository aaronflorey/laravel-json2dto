<?php

namespace App\Services;

use stdClass;
use Generator;
use App\Enums\CaseEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Nette\PhpGenerator\ClassType;
use Illuminate\Support\Collection;
use Nette\PhpGenerator\PhpNamespace;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class DtoGenerator
{
	private array $classes = [];
	private array $keyMap = [];
	private mixed $data;
	private PropertyGenerator $propertyGenerator;

	public function __construct(
		string $json,
		private string $namespace,
		bool $withSetters = false,
		bool $withGetters = false,
		CaseEnum $withCasing = CaseEnum::PASCAL
	) {
		$this->data = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);

		$this->propertyGenerator = new PropertyGenerator(
			$withSetters,
			$withGetters,
			$withCasing
		);
	}

	public function run(): void
	{
		$this->createClass($this->data, $this->namespace, 'Root');
	}

	public function files(NamespaceFolderResolver $namespaceResolver): Generator
	{
		$printer = new \Nette\PhpGenerator\PsrPrinter();

		foreach ($this->classes as $classNamespace) {
			$folder = $namespaceResolver->namespaceToFolder($classNamespace->getName());
			$className = array_values($classNamespace->getClasses())[0]->getName();
			$path = sprintf('%s/%s.php', $folder, $className);

			if (Str::startsWith($path, 'App/')) {
				$path = 'app' . substr($path, 3);
			}

			yield [
				'path'  => $path,
				'class' => $printer->printNamespace($classNamespace),
			];
		}
	}

	private function createClass(mixed $data, string $namespace, string $name)
	{
		$classNamespace = new PhpNamespace(ltrim($namespace, '\\'));
		$classNamespace->addUse(Data::class);

		ray()->table([
			$classNamespace,
		]);

		$class = $classNamespace->addClass(Str::studly(Str::singular($name) . ' Data'));
		$class->setExtends(Data::class);

		if ($data) {
			foreach ($data as $key => $value) {
				$this->addProperty($classNamespace, $class, $key, $value);
			}
		}

		$this->classes[] = $classNamespace;

		return $classNamespace;
	}

	private function addProperty(PhpNamespace $namespace, ClassType $class, int|string $key, mixed $value): void
	{
		if (!NameValidator::validateVariableName($key)) {
			// todo log
			return;
		}

		$type = $this->propertyGenerator->getType($value);

		if ($type === 'array') {
			$this->addCollectionProperty($namespace, $class, $key, $value);
			return;
		}

		if ($type === 'object') {
			if (!$value instanceof stdClass) {
				//TODO: i doubt this will happen but we should log it
                dump($type, $value);
				return;
			}

			$value = (array) $value;
			$nested = $this->addNested($namespace, $key, $value);
			$type = $this->getDtoFqcn($nested);
		}

		$this->propertyGenerator->addProperty(
			$namespace,
			$class,
			$key,
			$type
		);
	}

	private function addNested(PhpNamespace $namespace, string $key, array $value)
	{
		$keyHash = $this->hashKeys($value);

		if (!array_key_exists($keyHash, $this->keyMap)) {
			$this->keyMap[$keyHash] = $this->createClass($value, $this->namespace, $key);
		}

		$nestedNamespace = $this->keyMap[$keyHash];
		$nestedClass = Arr::first($nestedNamespace->getClasses());

		if ($namespace->getName() !== $nestedNamespace->getName()) {
			$namespace->addUse($nestedNamespace->getName() . $nestedClass->getName());
		}

		return $nestedClass;
	}

	private function hashKeys(mixed $value): string
	{
		return $this->hashValues(array_keys($value));
	}

	private function hashValues(array $keys): string
	{
		return md5(collect($keys)
			->flatten()
			->filter(fn ($key) => is_string($key))
			->unique()
			->map(function($key) {
				return Str::slug($key, '_');
			})
			->sort()
			->join('|'));
	}

	private function getDtoFqcn(ClassType $dto): string
	{
		return sprintf('\\%s\\%s', $dto->getNamespace()->getName(), $dto->getName());
	}

	private function addCollectionProperty(PhpNamespace $namespace, ClassType $class, int|string $key, mixed $values)
	{
		if (!Arr::isList($values)) {
			return null;
		}

		if (($types = $this->propertyGenerator->getTypes($values)) !== ['object']) {
			$property = $this->propertyGenerator->addProperty(
				$namespace,
				$class,
				$key,
				'array'
			);

			$property->addComment(sprintf('@var array<int, %s>', implode('|', $types)));

			return;
		}

		$keys = [];
		/** @var Collection<string, Collection<int, mixed>> $mergedValue */
		$mergedValue = collect();
		/** @var Collection<string, Collection<int, string>> $types */
		$types = collect();

		foreach ($values as $value) {
			$valueKeys = array_keys((array) $value);
			foreach ($valueKeys as $valueKey) {
				if (!in_array($valueKey, $keys)) {
					$keys[] = $valueKey;
				}

				if (!$types->has($valueKey)) {
					$types->put($valueKey, collect());
				}

				if (!$mergedValue->has($valueKey)) {
					$mergedValue->put($valueKey, collect());
				}

				$mergedValue->get($valueKey)->push($value->{$valueKey});
				$types->get($valueKey)->push($this->propertyGenerator->getType($value->{$valueKey}));
			}
		}

		$keyHash = $this->hashValues($keys);
		if (!array_key_exists($keyHash, $this->keyMap)) {
			$dataCollectionNamespace = $this->createClass(null, $this->namespace, $key);
			$dataCollection = Arr::first($dataCollectionNamespace->getClasses());

			foreach ($keys as $valueKey) {
				$type = $types->get($valueKey)->unique();

				if ($type->count() === 1) {
					$this->propertyGenerator->addProperty(
						$dataCollectionNamespace,
						$dataCollection,
						$valueKey,
						$type->first()
					);

                    continue;
				}

                //TODO: add support for multiple types
                dump($type, $valueKey, $mergedValue->get($valueKey)->toArray());
			}

			$this->keyMap[$keyHash] = $dataCollectionNamespace;
		}

		$nestedNamespace = $this->keyMap[$keyHash];
		$nestedClass = Arr::first($nestedNamespace->getClasses());

		if ($namespace->getName() !== $nestedNamespace->getName()) {
			$namespace->addUse($nestedNamespace->getName() . $nestedClass->getName());
		}

		$namespace->addUse(DataCollection::class);
		$namespace->addUse(DataCollectionOf::class);

		$property = $this->propertyGenerator->addProperty(
			$namespace,
			$class,
			$key,
			DataCollection::class
		);

		$property->addAttribute(
			basename(DataCollectionOf::class),
			['class' => ($nestedNamespace->getName() . '\\' . $nestedClass->getName())]
		);

		$property->addComment(
			sprintf('@var DataCollection&%s', $this->getDtoFqcn($nestedClass))
		);
	}
}
