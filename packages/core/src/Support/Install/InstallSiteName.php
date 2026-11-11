<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

/** The name the first site is created with: asked during install, defaulting to the application name. */
final class InstallSiteName
{
    public const int MAX_LENGTH = 255;

    /** The application name, unless it is empty or still Laravel's stock "Laravel". */
    public static function fallback(): string
    {
        $appName = config('app.name');

        if (! is_string($appName)) {
            return 'Capell';
        }

        $appName = trim($appName);

        if ($appName === '' || $appName === 'Laravel') {
            return 'Capell';
        }

        return $appName;
    }

    public static function validationError(string $name): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return (string) __('capell-core::install.site.name_required');
        }

        if (mb_strlen($name) > self::MAX_LENGTH) {
            return (string) __('capell-core::install.site.name_too_long', ['max' => self::MAX_LENGTH]);
        }

        return null;
    }
}
