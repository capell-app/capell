<?php

declare(strict_types=1);

namespace Capell\Core\Casts;

use Capell\Core\Support\Content\ContentStructureResolver;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Intentionally lenient JSON cast — does NOT use JsonCodec.
 *
 * get() returns the raw string on decode failure (not an empty array) so legacy
 * or malformed content surfaces unchanged rather than silently becoming []. set()
 * uses bare json_encode without JSON_THROW_ON_ERROR for the same reason: array
 * values supplied here originate from validated Data/form input, and a throwing
 * codec would convert benign edge cases into write-time exceptions.
 */
/**
 * @implements CastsAttributes<mixed, mixed>
 */
class DynamicContentCast implements CastsAttributes
{
    #[Override]
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $structure = ContentStructureResolver::resolve($model);

        if ($structure->isArray()) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : $value;
            }

            return $value;
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_string($value)) {
            return $value;
        }

        return '';
    }

    #[Override]
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        $structure = ContentStructureResolver::resolve($model);

        if ($structure->isArray()) {
            return is_array($value) ? json_encode($value) : (string) $value;
        }

        if (is_array($value)) {
            return json_encode($value);
        }

        if (is_string($value)) {
            return $value;
        }

        return '';
    }
}
