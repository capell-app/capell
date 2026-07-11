<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\Concerns\ComparesRuleValues;

abstract class ContextIdentityCondition implements FrontendRuleCondition
{
    use ComparesRuleValues;

    abstract protected function contextValue(FrontendRuleContextData $context): mixed;

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $contextValue = $this->contextValue($context);

        if ($contextValue === null) {
            return false;
        }

        $expectedValues = [
            ...$this->stringList($parameters['ids'] ?? $parameters['id'] ?? []),
            ...$this->stringList($parameters['keys'] ?? $parameters['key'] ?? []),
            ...$this->stringList($parameters['slugs'] ?? $parameters['slug'] ?? []),
            ...$this->stringList($parameters['codes'] ?? $parameters['code'] ?? []),
            ...$this->stringList($parameters['locales'] ?? $parameters['locale'] ?? []),
        ];

        foreach (['id', 'key', 'slug', 'code', 'locale'] as $attribute) {
            if ($this->matchesExpectedValue(data_get($contextValue, $attribute), $expectedValues)) {
                return true;
            }
        }

        return $this->matchesExpectedValue($contextValue, $expectedValues);
    }
}
