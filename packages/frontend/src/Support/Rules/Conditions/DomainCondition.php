<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;

final class DomainCondition implements FrontendRuleCondition
{
    public function key(): string
    {
        return 'domain';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $hosts = $parameters['hosts'] ?? [];

        if (is_string($hosts)) {
            $hosts = [$hosts];
        }

        if (! is_array($hosts)) {
            return false;
        }

        return collect($hosts)
            ->filter(fn (mixed $host): bool => is_string($host) && $host !== '')
            ->contains($context->request->getHost());
    }
}
