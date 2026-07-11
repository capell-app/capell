<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use InvalidArgumentException;

final class FrontendRuleConditionRegistry
{
    /** @var array<string, FrontendRuleCondition|class-string<FrontendRuleCondition>> */
    private array $conditions = [];

    /**
     * @param  FrontendRuleCondition|class-string<FrontendRuleCondition>  $condition
     */
    public function register(FrontendRuleCondition|string $condition): void
    {
        $resolvedCondition = is_string($condition) ? resolve($condition) : $condition;

        throw_unless($resolvedCondition instanceof FrontendRuleCondition, InvalidArgumentException::class, 'Frontend rule conditions must implement FrontendRuleCondition.');

        $this->conditions[$resolvedCondition->key()] = $condition;
    }

    public function get(string $key): ?FrontendRuleCondition
    {
        $condition = $this->conditions[$key] ?? null;

        if ($condition === null) {
            return null;
        }

        return is_string($condition) ? resolve($condition) : $condition;
    }
}
