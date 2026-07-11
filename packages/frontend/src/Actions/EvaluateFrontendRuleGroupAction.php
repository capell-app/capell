<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\FrontendRuleConditionRegistry;
use Lorisleiva\Actions\Concerns\AsAction;

final class EvaluateFrontendRuleGroupAction
{
    use AsAction;

    public function __construct(
        private readonly FrontendRuleConditionRegistry $conditions,
    ) {}

    /**
     * @param  array<string, mixed>  $group
     */
    public function handle(array $group, FrontendRuleContextData $context): bool
    {
        $operator = strtolower((string) ($group['operator'] ?? 'all'));
        $rules = $group['rules'] ?? [];

        if (! in_array($operator, ['all', 'any', 'not'], true)) {
            return false;
        }

        if (! is_array($rules) || $rules === []) {
            return false;
        }

        if ($operator === 'not') {
            $firstRule = $rules[array_key_first($rules)] ?? null;

            return is_array($firstRule) && ! $this->evaluateRule($firstRule, $context);
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                return false;
            }

            $result = $this->evaluateRule($rule, $context);

            if ($operator === 'any' && $result) {
                return true;
            }

            if ($operator !== 'any' && ! $result) {
                return false;
            }
        }

        return $operator !== 'any';
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function evaluateRule(array $rule, FrontendRuleContextData $context): bool
    {
        if (isset($rule['operator'])) {
            return $this->handle($rule, $context);
        }

        $conditionKey = $rule['condition'] ?? null;

        if (! is_string($conditionKey) || $conditionKey === '') {
            return false;
        }

        $condition = $this->conditions->get($conditionKey);

        if (! $condition instanceof FrontendRuleCondition) {
            return false;
        }

        $parameters = $rule['parameters'] ?? [];

        return is_array($parameters) && $condition->evaluate($parameters, $context);
    }
}
