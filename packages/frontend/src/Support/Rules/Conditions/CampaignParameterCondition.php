<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;

final class CampaignParameterCondition implements FrontendRuleCondition
{
    public function __construct(
        private readonly QueryParameterCondition $queryParameter,
    ) {}

    public function key(): string
    {
        return 'campaign_parameter';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $name = $parameters['name'] ?? $parameters['key'] ?? null;

        if (! is_string($name) || ! in_array($name, $this->allowedNames(), true)) {
            return false;
        }

        return $this->queryParameter->evaluate($parameters, $context);
    }

    /**
     * @return list<string>
     */
    private function allowedNames(): array
    {
        return [
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_term',
            'utm_content',
            'campaign',
        ];
    }
}
