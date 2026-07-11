<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\Concerns\ComparesRuleValues;

final class QueryParameterCondition implements FrontendRuleCondition
{
    use ComparesRuleValues;

    public function key(): string
    {
        return 'query_parameter';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $name = $parameters['name'] ?? $parameters['key'] ?? null;

        if (! is_string($name) || $name === '' || ! $context->request->query->has($name)) {
            return false;
        }

        $values = $this->stringList($parameters['values'] ?? $parameters['value'] ?? []);

        return $values === [] || $this->matchesExpectedValue($context->request->query($name), $values);
    }
}
