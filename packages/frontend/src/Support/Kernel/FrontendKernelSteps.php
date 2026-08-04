<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Kernel;

use Capell\Frontend\Support\Kernel\Steps\ApplyLocaleStep;
use Capell\Frontend\Support\Kernel\Steps\LayoutResolverStep;
use Capell\Frontend\Support\Kernel\Steps\NotifySubscribersStep;
use Capell\Frontend\Support\Kernel\Steps\PageResolveStep;
use Capell\Frontend\Support\Kernel\Steps\RegisterThemeViewsStep;
use Capell\Frontend\Support\Kernel\Steps\SetUrlGeneratorStep;
use Capell\Frontend\Support\Kernel\Steps\SiteResolveStep;
use Capell\Frontend\Support\Kernel\Steps\ThemeResolverStep;

/**
 * Single source of truth for the frontend kernel pipeline.
 *
 * Both the HTTP kernel binding and the Livewire context restoration path consume
 * this list; a step registered here runs on full page requests and on Livewire
 * updates, which never re-run the `frontend.resolve` middleware.
 */
final class FrontendKernelSteps
{
    /** @var list<class-string> */
    public const array DEFAULTS = [
        SiteResolveStep::class,
        ApplyLocaleStep::class,
        SetUrlGeneratorStep::class,
        PageResolveStep::class,
        LayoutResolverStep::class,
        ThemeResolverStep::class,
        RegisterThemeViewsStep::class,
        NotifySubscribersStep::class,
    ];

    /** @return array<int, callable|string> */
    public static function configured(): array
    {
        $steps = config('frontend.kernel.steps', self::DEFAULTS);

        return is_array($steps) ? $steps : self::DEFAULTS;
    }

    /**
     * @param  list<class-string>  $excluded
     * @return array<int, string>
     */
    public static function without(array $excluded): array
    {
        return array_values(array_filter(
            self::configured(),
            fn (mixed $step): bool => is_string($step) && ! in_array($step, $excluded, true),
        ));
    }
}
