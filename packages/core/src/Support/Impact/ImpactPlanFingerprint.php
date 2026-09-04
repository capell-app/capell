<?php

declare(strict_types=1);

namespace Capell\Core\Support\Impact;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use JsonException;

final class ImpactPlanFingerprint
{
    /**
     * @param  array<string, mixed>  $plan
     *
     * @throws JsonException
     */
    public static function for(Model $target, array $plan): string
    {
        return self::hash([
            'target' => [
                'type' => $target::class,
                'key' => (string) $target->getKey(),
                'attributes' => $target->getAttributes(),
            ],
            'plan' => $plan,
        ]);
    }

    /**
     * @param  array<string, mixed>  $plan
     *
     * @throws JsonException
     */
    public static function forPlan(array $plan): string
    {
        return self::hash(['plan' => $plan]);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private static function hash(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalise($payload), JSON_THROW_ON_ERROR));
    }

    private static function canonicalise(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalise(...), $value);
        }

        ksort($value);

        return array_map(self::canonicalise(...), $value);
    }
}
