<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Locale;

use Capell\Core\Octane\Resettable;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\Facades\Date;

/**
 * Applies the resolved site locale to the application for the current request and
 * guarantees the incoming default locale is restored, so a long-running worker
 * cannot bleed one site's locale into an unrelated later request.
 */
final class FrontendLocaleScope implements Resettable
{
    private ?string $previousLocale = null;

    private bool $terminationRegistered = false;

    public function __construct(private readonly Application $app) {}

    public static function isSafeLocale(string $locale): bool
    {
        return $locale !== ''
            && ! str_contains($locale, '/')
            && ! str_contains($locale, '\\')
            && preg_match('/^[A-Za-z0-9_-]+$/', $locale) === 1;
    }

    public function apply(string $locale): void
    {
        if (! self::isSafeLocale($locale)) {
            return;
        }

        $this->previousLocale ??= $this->app->getLocale();

        $this->registerTermination();
        $this->setLocale($locale);
    }

    public function restore(): void
    {
        if ($this->previousLocale === null) {
            return;
        }

        $locale = $this->previousLocale;
        $this->previousLocale = null;
        $this->terminationRegistered = false;

        $this->setLocale($locale);
    }

    public function flushOctaneState(): void
    {
        $this->restore();
    }

    private function setLocale(string $locale): void
    {
        $this->app->setLocale($locale);
        $this->app->make(Translator::class)->setLocale($locale);

        Date::setLocale($locale);
        CarbonImmutable::setLocale($locale);
    }

    private function registerTermination(): void
    {
        if ($this->terminationRegistered) {
            return;
        }

        $this->terminationRegistered = true;
        $this->app->terminating(function (): void {
            $this->restore();
        });
    }
}
