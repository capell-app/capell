<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Rules\Conditions;

use Capell\Frontend\Contracts\FrontendRuleCondition;
use Capell\Frontend\Data\FrontendRuleContextData;
use Capell\Frontend\Support\Rules\Conditions\Concerns\ComparesRuleValues;

final class LocaleCondition implements FrontendRuleCondition
{
    use ComparesRuleValues;

    public function key(): string
    {
        return 'locale';
    }

    public function evaluate(array $parameters, FrontendRuleContextData $context): bool
    {
        $locales = $this->stringList($parameters['locales'] ?? $parameters['locale'] ?? []);
        if ($this->matchesExpectedValue($context->request->getLocale(), $locales)) {
            return true;
        }

        return $this->matchesExpectedValue(app()->getLocale(), $locales);
    }
}
