<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel\Steps;

use Capell\Core\Models\Theme;
use Capell\Frontend\Data\FrontendContext;
use Capell\Frontend\Data\FrontendWork;
use Capell\Frontend\Support\View\ThemeChainResolver;
use Capell\Frontend\Support\View\ThemeViewRegistrar;
use Closure;

final class RegisterThemeViewsStep
{
    public function __construct(
        private readonly ThemeChainResolver $resolver,
        private readonly ThemeViewRegistrar $registrar,
    ) {}

    public function handle(FrontendWork $work, Closure $next): FrontendWork
    {
        $context = $work->context();
        if (! $context instanceof FrontendContext) {
            return $next($work);
        }

        $theme = $context->theme();
        if (! $theme instanceof Theme || $theme->key === '') {
            return $next($work);
        }

        $paths = $this->resolver->resolve($theme);
        $this->registrar->register($paths, $theme->key);

        return $next($work);
    }
}
