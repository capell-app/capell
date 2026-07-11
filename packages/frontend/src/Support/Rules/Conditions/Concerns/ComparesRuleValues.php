<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions\Concerns;

trait ComparesRuleValues
{
    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(collect($value)
            ->filter(fn (mixed $item): bool => is_string($item) && $item !== '')
            ->values()
            ->all());
    }

    /**
     * @param  list<string>  $expectedValues
     */
    private function matchesExpectedValue(mixed $actualValue, array $expectedValues): bool
    {
        if ($expectedValues === []) {
            return false;
        }

        if (is_bool($actualValue)) {
            $actualValue = $actualValue ? 'true' : 'false';
        }

        if (! is_scalar($actualValue)) {
            return false;
        }

        return in_array((string) $actualValue, $expectedValues, true);
    }
}
