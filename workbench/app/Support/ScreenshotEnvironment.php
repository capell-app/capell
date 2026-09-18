<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Capell\Core\Models\SiteDomain;
use RuntimeException;

final class ScreenshotEnvironment
{
    public static function verify(): void
    {
        throw_unless(
            app()->environment('production') && config('app.debug') === false && config('session.driver') === 'file',
            RuntimeException::class,
            'Screenshot capture requires production mode, disabled debug output and persistent file sessions.',
        );
        $displayHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        throw_unless(
            is_string($displayHost) && str_ends_with($displayHost, '.example'),
            RuntimeException::class,
            'Screenshot capture requires an intercepted .example display origin.',
        );
        throw_unless(
            SiteDomain::query()->where('default', true)->where('status', true)->where('domain', $displayHost)->exists(),
            RuntimeException::class,
            'The screenshot site still has a stale display domain. Prepare an owned screenshot workbench before capture; reusing a loopback-domain database is not accepted.',
        );
        $stylesheet = public_path('build/filament/theme.css');
        throw_unless(
            is_file($stylesheet) && str_contains((string) file_get_contents($stylesheet), '.fi-'),
            RuntimeException::class,
            'The screenshot admin theme has not been compiled. Run the workbench theme builder before capture.',
        );
    }
}
