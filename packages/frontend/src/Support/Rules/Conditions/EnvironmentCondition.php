<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\Concerns\ComparesRuleValues;

final class EnvironmentCondition implements FrontendRuleCondition
{
    use ComparesRuleValues;

    public function key(): string
    {
        return 'environment';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $environments = $this->stringList($parameters['environments'] ?? $parameters['environment'] ?? []);

        return $environments !== [] && app()->environment($environments);
    }
}
