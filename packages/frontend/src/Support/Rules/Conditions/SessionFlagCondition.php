<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\Concerns\ComparesRuleValues;

final class SessionFlagCondition implements FrontendRuleCondition
{
    use ComparesRuleValues;

    public function key(): string
    {
        return 'session_flag';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $name = $parameters['name'] ?? $parameters['key'] ?? null;

        if (! is_string($name) || $name === '' || ! $context->request->hasSession()) {
            return false;
        }

        $session = $context->request->session();

        if (! $session->has($name)) {
            return false;
        }

        $values = $this->stringList($parameters['values'] ?? $parameters['value'] ?? []);

        return $values === [] || $this->matchesExpectedValue($session->get($name), $values);
    }
}
