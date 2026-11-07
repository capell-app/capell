<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Fixtures\Filament\Plugin;

use Illuminate\Support\ServiceProvider;

/** Registers another standalone panel callback from a provider registered after boot. */
final class LateStandaloneCallbackRouteProvider extends ServiceProvider
{
    public const string URI = '/admin/standalone/late-callback';

    public function boot(): void
    {
        StandaloneCallbackRouteProvider::callback(self::URI, 'runtime-standalone.late-callback');
    }
}
