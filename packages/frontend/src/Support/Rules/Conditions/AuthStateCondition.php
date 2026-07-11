<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;

final class AuthStateCondition implements FrontendRuleCondition
{
    public function key(): string
    {
        return 'auth_state';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $state = $parameters['state'] ?? null;

        return match ($state) {
            'authenticated' => $context->request->user() !== null,
            'guest' => $context->request->user() === null,
            default => false,
        };
    }
}
