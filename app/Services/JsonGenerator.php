<?php

namespace App\Services;

use stdClass;
use Generator;
use App\Enums\CaseEnum;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\ClassType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Nette\PhpGenerator\PhpNamespace;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class JsonGenerator
{
	private array $classes = [];
	private array $keyMap = [];
	private readonly mixed $data;
	private readonly PropertyGenerator $propertyGenerator;

	public function __construct(
		string $json,
		private readonly string $namespace,
		private readonly string $baseFilename,
		bool $withSetters = false,
		bool $withGetters = false,
		?CaseEnum $withCasing = null,
		bool $withDates = false,
	) {
		$this->data = json_decode($json, false, 512, \JSON_THROW_ON_ERROR);

		$this->propertyGenerator = new PropertyGenerator(
			$withSetters,
			$withGetters,
			$withCasing,
			$withDates
		);
	}

	public function run(): void
	{
		$this->createClass($this->data, $this->namespace, $this->baseFilename);
	}

	/**
	 * @return Generator<PhpFile>
	 */
	public function files(): Generator
	{
		foreach ($this->classes as $classNamespace) {
			$file = new PhpFile();
			$file->addNamespace($classNamespace);
			$file->setStrictTypes();

			yield $file;
		}
	}

	private function createClass(mixed $data, string $namespace, string $name): PhpNamespace
	{
		$classNamespace = new PhpNamespace(ltrim($namespace, '\\'));
		$classNamespace->addUse(Data::class);

        $className = Str::of($name)
            ->singular()
            ->append(' Data')
            ->studly()
            ->replace('DataData', 'Data')
            ->toString();

		$class = $classNamespace->addClass($className);
		$class->setExtends(Data::class);

		if (is_array($data) && Arr::isList($data)) {
			$data = $this->createObjectFromList($data);
		}

		foreach ($data as $key => $value) {
			$this->addProperty($classNamespace, $class, $key, $value);
		}

		$this->classes[] = $classNamespace;

		return $classNamespace;
	}

	private function addProperty(PhpNamespace $namespace, ClassType $class, int|string $key, mixed $value): void
	{
		if (!NameValidator::validateVariableName($key)) {
			Log::warning(sprintf('Invalid property name: %s', $key));
			return;
		}

		$type = $this->propertyGenerator->getType($value);

		if ($type === 'array') {
			if ($this->isMultidimensional($value)) {
				$this->addCollectionProperty($namespace, $class, $key, $value);
				return;
			}

			$types = $this->propertyGenerator->getTypes($value);
			$type = Arr::join($types, '|');
		}

		if ($type === 'object' && $value instanceof stdClass) {
			$nestedNamespace = $this->addNested($namespace, $key, (array) $value);
			$type = $this->classFqdn($nestedNamespace);
		}

		$this->propertyGenerator->addProperty(
			$namespace,
			$class,
			$key,
			$type
		);
	}

	private function addNested(PhpNamespace $namespace, string $key, array $value): PhpNamespace
	{
		$keyHash = $this->hashKeys($value);
		if (!array_key_exists($keyHash, $this->keyMap)) {
			$this->keyMap[$keyHash] = $this->createClass($value, $this->namespace, $key);
		}

		$nestedNamespace = $this->keyMap[$keyHash];
		$namespace->addUse($this->classFqdn($nestedNamespace));

		return $nestedNamespace;
	}

	private function hashKeys(mixed $value): string
	{
		return $this->hashValues(array_keys($value));
	}

	private function hashValues(array $keys): string
	{
		return md5(collect($keys)
			->flatten()
			->filter(fn ($key): bool => is_string($key))
			->unique()
			->map(fn ($key): string => Str::slug($key, '_'))
			->sort()
			->join('|'));
	}

	private function classFqdn(ClassType|PhpNamespace $class): string
	{
		if ($class instanceof ClassType) {
			return sprintf('\\%s\\%s', $class->getNamespace()->getName(), $class->getName());
		}

		return sprintf('\\%s\\%s', $class->getName(), $this->namespaceClass($class)->getName());
	}

	private function addCollectionProperty(PhpNamespace $namespace, ClassType $class, int|string $key, mixed $values): void
	{
		if (!Arr::isList($values)) {
			return;
		}

		$object = $this->createObjectFromList($values);
		$nestedNamespace = $this->addNested($namespace, $key, (array) $object);
		$nestedClass = $this->namespaceClass($nestedNamespace);

		$namespace->addUse($this->classFqdn($nestedNamespace));
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
			[
				new Literal($nestedClass->getName() . '::class')
			]
		);

		$property->addComment(
			sprintf('@var DataCollection<%s>', $nestedClass->getName())
		);
	}

	private function createObjectFromList(array $values): stdClass
	{
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

				if ($value->{$valueKey}) {
					$mergedValue->get($valueKey)->push($value->{$valueKey});
					$types->get($valueKey)->push($this->propertyGenerator->getType($value->{$valueKey}));
				}
			}
		}

		$finalValue = new StdClass();

		foreach ($keys as $key) {
			$type = $types->get($key)->unique()->filter();

			if ($type->count() === 0) {
				$finalValue->{$key} = null;

				continue;
			}

			if ($type->count() === 1) {
				foreach ($mergedValue->get($key) as $value) {
					if ($this->propertyGenerator->getType($value) === $type->first()) {
						$finalValue->{$key} = $value;
					}
				}

				continue;
			}

			Log::debug(sprintf("Couldn't figure out type for %s", $key));
			$finalValue->{$key} = null;
		}

		return $finalValue;
	}

	private function namespaceClass(PhpNamespace $namespace): ClassType
	{
		return Arr::first($namespace->getClasses());
	}

	private function isMultidimensional(array $array): bool
	{
		foreach ($array as $row) {
			if (is_array($row) || is_object($row)) {
				return true;
			}
		}

		return false;
	}
}
