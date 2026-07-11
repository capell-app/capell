<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRuleContextData;

interface FrontendRuleCondition
{
    public function key(): string;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function evaluate(array $parameters, FrontendRuleContextData $context): bool;
}
