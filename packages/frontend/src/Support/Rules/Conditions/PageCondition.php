<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Data\FrontendRuleContextData;

final class PageCondition extends ContextIdentityCondition
{
    public function key(): string
    {
        return 'page';
    }

    protected function contextValue(FrontendRuleContextData $context): mixed
    {
        return $context->page;
    }
}
