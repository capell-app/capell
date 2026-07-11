<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Carbon\CarbonImmutable;
use Throwable;

final class DateWindowCondition implements FrontendRuleCondition
{
    public function key(): string
    {
        return 'date_window';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $startsAt = $this->date($parameters['starts_at'] ?? null);
        $endsAt = $this->date($parameters['ends_at'] ?? null);

        if ($startsAt instanceof CarbonImmutable && $startsAt->isFuture()) {
            return false;
        }

        if ($endsAt instanceof CarbonImmutable && $endsAt->isPast()) {
            return false;
        }

        return $startsAt instanceof CarbonImmutable || $endsAt instanceof CarbonImmutable;
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
