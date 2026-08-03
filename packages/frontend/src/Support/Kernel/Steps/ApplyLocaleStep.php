<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Models\Language;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\Locale\FrontendLocaleScope;
use Closure;

/**
 * The locale is derived only from the resolved site language (a pure function of
 * host + path). Request headers such as Accept-Language must never influence it:
 * the public HTML cache is keyed on host + path alone.
 */
final class ApplyLocaleStep
{
    public function __construct(private readonly FrontendLocaleScope $scope) {}

    public function handle(FrontendWork $work, Closure $next): mixed
    {
        $language = $work->state->language();

        if ($language instanceof Language) {
            $locale = filled($language->locale) ? (string) $language->locale : (string) $language->code;

            $this->scope->apply($locale);
        }

        return $next($work);
    }
}
