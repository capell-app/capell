<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;

final class PathCondition implements FrontendRuleCondition
{
    public function key(): string
    {
        return 'path';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $patterns = $parameters['patterns'] ?? [];

        if (is_string($patterns)) {
            $patterns = [$patterns];
        }

        if (! is_array($patterns)) {
            return false;
        }

        return collect($patterns)
            ->filter(fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '')
            ->contains(fn (string $pattern): bool => $context->request->is(ltrim($pattern, '/')));
    }
}
