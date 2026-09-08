<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Core\Models\Site;
use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\Concerns\ComparesRuleValues;

final class RoleCondition implements FrontendRuleCondition
{
    use ComparesRuleValues;

    public function key(): string
    {
        return 'role';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $roles = $this->stringList($parameters['roles'] ?? $parameters['role'] ?? []);
        $user = $context->request->user();

        if ($roles === [] || $user === null || ! method_exists($user, 'hasRole')) {
            return false;
        }

        if (config('permission.teams')) {
            return $context->site instanceof Site && method_exists($user, 'hasRoleForSite')
                && collect($roles)->contains(fn (string $role): bool => $user->hasRoleForSite($context->site, $role));
        }

        return collect($roles)->contains(fn (string $role): bool => $user->hasRole($role));
    }
}
