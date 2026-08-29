<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Closure;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use ReflectionClass;
use RuntimeException;
use Spatie\LaravelData\Data;
use stdClass;
use Throwable;
use Traversable;

/** One normalised, validated public render-data value. */
final readonly class PublicRenderDataContributionData
{
    public object $value;

    /** @param list<string> $surrogateKeys */
    public function __construct(
        object $value,
        public array $surrogateKeys = [],
    ) {
        $activeObjects = [];
        self::assertSafeGraph($value, $activeObjects);
        $this->value = $this->publicObject($value);

        foreach ($this->surrogateKeys as $key) {
            throw_unless(is_string($key) && preg_match('/\A[A-Za-z0-9_-]+\z/', $key) === 1, RuntimeException::class, 'Public render-data contributors require valid surrogate keys.');
        }

        try {
            json_encode($this->value, JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Public render-data contributor values must be serialisable public data.', $throwable->getCode(), previous: $throwable);
        }
    }

    private static function publicValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw_if(is_resource($value) || $value instanceof Closure, RuntimeException::class, 'Public render-data contributor values must be serialisable public data and cannot contain resources or closures.');

        if (is_array($value)) {
            return array_map(self::publicValue(...), $value);
        }

        throw_if($value instanceof Model, RuntimeException::class, 'Public render-data contributor values cannot contain Eloquent models.');

        if ($value instanceof Data) {
            return self::publicValue($value->toArray());
        }

        if ($value instanceof JsonSerializable) {
            return self::publicValue($value->jsonSerialize());
        }

        if ($value instanceof Traversable) {
            return self::publicValue(iterator_to_array($value));
        }

        if ($value instanceof stdClass) {
            return (object) array_map(self::publicValue(...), get_object_vars($value));
        }

        $reflection = new ReflectionClass($value);
        $public = new stdClass;

        foreach ($reflection->getProperties() as $property) {
            if (! $property->isInitialized($value)) {
                continue;
            }

            $propertyValue = $property->getValue($value);
            self::assertSafeHiddenValue($propertyValue);

            if ($property->isPublic()) {
                $public->{$property->getName()} = self::publicValue($propertyValue);
            }
        }

        return $public;
    }

    /** @param array<int, true> $activeObjects */
    private static function assertSafeGraph(mixed $value, array &$activeObjects): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        throw_if(is_resource($value) || $value instanceof Closure, RuntimeException::class, 'Public render-data contributor values must be serialisable public data and cannot contain resources or closures.');

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertSafeGraph($item, $activeObjects);
            }

            return;
        }

        throw_if($value instanceof Model, RuntimeException::class, 'Public render-data contributor values cannot contain Eloquent models.');

        $objectId = spl_object_id($value);
        throw_if(isset($activeObjects[$objectId]), RuntimeException::class, 'Public render-data contributor values cannot contain cyclic object graphs.');
        $activeObjects[$objectId] = true;

        try {
            if ($value instanceof Traversable) {
                foreach ($value as $item) {
                    self::assertSafeGraph($item, $activeObjects);
                }
            }

            if ($value instanceof stdClass) {
                foreach (get_object_vars($value) as $item) {
                    self::assertSafeGraph($item, $activeObjects);
                }
            } else {
                foreach (new ReflectionClass($value)->getProperties() as $property) {
                    if (! $property->isInitialized($value)) {
                        continue;
                    }

                    self::assertSafeGraph($property->getValue($value), $activeObjects);
                }
            }

            if ($value instanceof JsonSerializable) {
                self::assertSafeGraph($value->jsonSerialize(), $activeObjects);
            }
        } finally {
            unset($activeObjects[$objectId]);
        }
    }

    private static function assertSafeHiddenValue(mixed $value): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        throw_if(is_resource($value) || $value instanceof Closure, RuntimeException::class, 'Public render-data contributor values must be serialisable public data and cannot contain hidden resources or closures.');

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertSafeHiddenValue($item);
            }

            return;
        }

        if (is_object($value)) {
            throw_if($value instanceof Model, RuntimeException::class, 'Public render-data contributor values cannot hide Eloquent models.');

            foreach (new ReflectionClass($value)->getProperties() as $property) {
                if ($property->isInitialized($value)) {
                    self::assertSafeHiddenValue($property->getValue($value));
                }
            }
        }
    }

    private function publicObject(object $value): object
    {
        $normalised = self::publicValue($value);

        if (is_array($normalised)) {
            $normalised = (object) $normalised;
        }

        throw_unless(is_object($normalised), RuntimeException::class, 'Public render-data contributor values must be public objects.');

        return $normalised;
    }
}
