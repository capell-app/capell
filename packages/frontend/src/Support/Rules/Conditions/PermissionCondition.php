<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\Concerns\ComparesRuleValues;

final class PermissionCondition implements FrontendRuleCondition
{
    use ComparesRuleValues;

    public function key(): string
    {
        return 'permission';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $permissions = $this->stringList($parameters['permissions'] ?? $parameters['permission'] ?? []);
        $user = $context->request->user();

        if ($permissions === [] || $user === null) {
            return false;
        }

        return collect($permissions)->contains(fn (string $permission): bool => $user->can($permission));
    }
}
