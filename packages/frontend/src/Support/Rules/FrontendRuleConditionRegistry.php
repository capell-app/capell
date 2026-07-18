<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Capell\Frontend\Contracts\FrontendRuleCondition;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<FrontendRuleCondition|class-string<FrontendRuleCondition>> */
final class FrontendRuleConditionRegistry extends AbstractKeyedRegistry
{
    /**
     * @param  FrontendRuleCondition|class-string<FrontendRuleCondition>  $condition
     */
    public function register(FrontendRuleCondition|string $condition): void
    {
        $resolvedCondition = is_string($condition) ? resolve($condition) : $condition;

        throw_unless($resolvedCondition instanceof FrontendRuleCondition, InvalidArgumentException::class, 'Frontend rule conditions must implement FrontendRuleCondition.');

        $this->setItem($resolvedCondition->key(), $condition);
    }

    public function get(string $key): ?FrontendRuleCondition
    {
        $condition = $this->getItem($key);

        if ($condition === null) {
            return null;
        }

        return is_string($condition) ? resolve($condition) : $condition;
    }
}
