<?php

declare(strict_types=1);

namespace Capell\Frontend\Contracts;

use Capell\Frontend\Data\FrontendRuleContextData;

/**
 * Kept only so classes in already-published extension releases that implement it still load.
 *
 * The frontend rule engine that evaluated these conditions has been removed, and nothing in
 * Capell registers or evaluates a condition any more. Its registry is deliberately not restored:
 * published releases register their conditions only when that registry class exists, so they stay
 * inert. Removed in 2.0.
 *
 * @deprecated 1.x compatibility only; removed in 2.0. Nothing evaluates frontend rule conditions. Do not implement this in new code.
 */
interface FrontendRuleCondition
{
    public function key(): string;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function evaluate(array $parameters, FrontendRuleContextData $context): bool;
}
