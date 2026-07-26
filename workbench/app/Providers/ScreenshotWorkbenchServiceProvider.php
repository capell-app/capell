<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Override;
use Workbench\App\Support\MarketplaceFixture;

final class ScreenshotWorkbenchServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $database = config('screenshot.database');

        if (is_string($database) && $database !== '') {
            Config::set('database.default', 'sqlite');
            Config::set('database.connections.sqlite.database', $database);
            Config::set('database.connections.sqlite.url');
        }

        config([
            'capell-marketplace.marketplace.base_url' => 'http://127.0.0.1:8145/api/v1',
            'capell-marketplace.marketplace.web_url' => 'http://127.0.0.1:8145',
        ]);
    }

    public function boot(): void
    {
        File::ensureDirectoryExists(resource_path('css'));

        if (! File::exists(resource_path('css/app.css'))) {
            File::put(resource_path('css/app.css'), "/* Screenshot workbench frontend entrypoint. */\n");
        }

        // scripts/screenshots/build-filament-theme-css.mjs compiles the real
        // Filament admin theme to build/filament/theme.css during
        // prepare-workbench.sh. The admin panel's viteTheme() REPLACES the
        // published app.css, so a stub here means an unstyled panel — only
        // write placeholders when the built theme is missing entirely (a
        // fresh app that has not been prepared yet), and never overwrite the
        // compiled bundle.
        File::ensureDirectoryExists(public_path('build/filament'));

        if (! File::exists(public_path('build/filament/theme.css'))) {
            File::put(
                public_path('build/filament/theme.css'),
                "/* Placeholder — run scripts/screenshots/prepare-workbench.sh to build the real Filament theme. */\n",
            );
        }

        if (! File::exists(public_path('build/filament/manifest.json'))) {
            File::put(
                public_path('build/filament/manifest.json'),
                json_encode([
                    'resources/css/filament/admin/theme.css' => [
                        'file' => 'theme.css',
                        'isEntry' => true,
                        'src' => 'resources/css/filament/admin/theme.css',
                    ],
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
            );
        }

        $this->publishFilamentAssets();

        $this->loadRoutesFrom(__DIR__ . '/../../routes/screenshot-fixtures.php');

        Route::get('/__ping', static fn (): string => 'pong');

        $baseUrl = rtrim((string) config('capell-marketplace.marketplace.base_url'), '/');
        $webUrl = rtrim((string) config('capell-marketplace.marketplace.web_url'), '/');

        Http::fake([
            $baseUrl . '/extensions/seo-suite' => Http::response(
                MarketplaceFixture::extensionResponse($webUrl),
                200,
            ),
        ]);
    }

    /**
     * Republish Filament's CSS and JS when the testbench document root has lost
     * them.
     *
     * `filament:assets` normally runs as a side effect of `capell:install`
     * during scripts/screenshots/prepare-workbench.sh. Runs that reuse an
     * existing app skip that script, and any composer install re-extracts
     * orchestra/testbench-core and deletes its public/css and public/js. The
     * capture then succeeds against a page with no stylesheet at all, which
     * looks like a styling regression rather than a missing prerequisite.
     */
    private function publishFilamentAssets(): void
    {
        if (File::exists(public_path('css/filament/filament/app.css'))) {
            return;
        }

        Artisan::call('filament:assets');
    }
}
