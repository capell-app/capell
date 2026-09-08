<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Core\Models\Site;
use Capell\Core\Support\Permissions\PermissionTeamContext;
use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\Concerns\ComparesRuleValues;
use Illuminate\Database\Eloquent\Model;

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

        if (config('permission.teams')) {
            if (! $context->site instanceof Site) {
                return false;
            }

            return PermissionTeamContext::run(
                $context->site->getKey(),
                fn (): bool => collect($permissions)->contains(fn (string $permission): bool => $user->can($permission)),
                $user instanceof Model ? $user : null,
            );
        }

        return collect($permissions)->contains(fn (string $permission): bool => $user->can($permission));
    }
}
