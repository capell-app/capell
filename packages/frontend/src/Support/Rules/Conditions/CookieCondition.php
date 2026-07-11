<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\Concerns\ComparesRuleValues;

final class CookieCondition implements FrontendRuleCondition
{
    use ComparesRuleValues;

    public function key(): string
    {
        return 'cookie';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $name = $parameters['name'] ?? $parameters['key'] ?? null;

        if (! is_string($name) || $name === '' || ! $context->request->cookies->has($name)) {
            return false;
        }

        $values = $this->stringList($parameters['values'] ?? $parameters['value'] ?? []);

        return $values === [] || $this->matchesExpectedValue($context->request->cookies->get($name), $values);
    }
}
