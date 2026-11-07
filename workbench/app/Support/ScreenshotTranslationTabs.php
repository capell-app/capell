<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Admin\Enums\TranslationTabsOrientation;

/**
 * Screenshot workbench only. The runner cannot change configuration between
 * captures, so a fixture route stores the wanted translation tab orientation
 * in the session and each Filament request applies it. This listens to
 * Filament rather than joining the web group, because the admin panel refuses
 * routes with middleware it does not own. Unknown values are ignored.
 */
final class ScreenshotTranslationTabs
{
    public const string SESSION_KEY = 'screenshot.translation_tabs_orientation';

    public static function apply(): void
    {
        $orientation = session(self::SESSION_KEY);

        if (is_string($orientation) && TranslationTabsOrientation::tryFrom($orientation) instanceof TranslationTabsOrientation) {
            config()->set('capell-admin.translation_tabs_orientation', $orientation);
        }
    }
}
