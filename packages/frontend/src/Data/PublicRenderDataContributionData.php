<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use RuntimeException;
use Spatie\LaravelData\Data;
use Throwable;
use Traversable;

/**
 * One validated public render-data contribution.
 *
 * The value is deliberately an object. Its public representation is checked
 * at construction time so a contributor cannot put an Eloquent model, a
 * resource, a closure, or another non-serialisable value into public data.
 */
final readonly class PublicRenderDataContributionData
{
    /**
     * @param  list<string>  $surrogateKeys
     * @param  list<PublicRenderDataCacheDependencyData>  $cacheDependencies
     */
    public function __construct(
        public object $value,
        public string $fingerprint,
        public array $surrogateKeys = [],
        public array $cacheDependencies = [],
    ) {
        throw_if($this->fingerprint === '', RuntimeException::class, 'Public render-data contributors require a non-empty fingerprint.');

        foreach ($this->surrogateKeys as $key) {
            throw_unless(is_string($key) && preg_match('/\A[A-Za-z0-9_-]+\z/', $key) === 1, RuntimeException::class, 'Public render-data contributors require valid surrogate keys.');
        }

        foreach ($this->cacheDependencies as $dependency) {
            throw_unless($dependency instanceof PublicRenderDataCacheDependencyData, RuntimeException::class, 'Public render-data contributors require typed cache dependencies.');
        }

        try {
            serialize($this->value);
            json_encode(self::publicValue($this->value), JSON_THROW_ON_ERROR);
        } catch (Throwable $throwable) {
            throw new RuntimeException('Public render-data contributor values must be serialisable public data.', previous: $throwable);
        }
    }

    private static function publicValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        throw_if(is_resource($value), RuntimeException::class, 'Public render-data contributor values cannot contain resources.');

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

        return self::publicValue(get_object_vars($value));
    }
}
