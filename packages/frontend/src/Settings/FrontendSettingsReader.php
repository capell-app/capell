<?php

declare(strict_types=1);

namespace Capell\Frontend\Settings;

use Capell\Core\Facades\CapellCore;
use Capell\Frontend\Contracts\FrontendSettingsReaderInterface;
use Capell\Frontend\Providers\FrontendServiceProvider;
use Throwable;

class FrontendSettingsReader implements FrontendSettingsReaderInterface
{
    private const string SCOPED_SETTINGS_KEY = 'capell.frontend.settings.current';

    public function settings(): FrontendSettings
    {
        /** @var class-string<FrontendSettings> $settingsClass */
        $settingsClass = CapellCore::getPackage(FrontendServiceProvider::$packageName)->setting;

        $cacheKey = self::SCOPED_SETTINGS_KEY . ':' . $settingsClass;
        $application = app();

        if (! $application->bound($cacheKey)) {
            $application->scoped($cacheKey, fn (): FrontendSettings => resolve($settingsClass));
        }

        /** @var FrontendSettings $settings */
        $settings = $application->make($cacheKey);

        return $settings;
    }

    public function minifyHtml(): bool
    {
        try {
            return $this->settings()->minify_html;
        } catch (Throwable) {
            return config('capell-frontend.minify_html', true) === true;
        }
    }

    public function defaultLayoutKey(): string
    {
        return config('capell-frontend.default_layout', 'default');
    }

    public function defaultThemeKey(): string
    {
        return config('capell-frontend.foundation_theme', 'default');
    }
}
