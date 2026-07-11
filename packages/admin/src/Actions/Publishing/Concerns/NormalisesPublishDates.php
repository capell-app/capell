<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Publishing\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

trait NormalisesPublishDates
{
    protected function dateAttribute(Model $record, string $attribute): ?CarbonImmutable
    {
        $value = $record->getAttribute($attribute);

        if ($value instanceof CarbonImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }
}
