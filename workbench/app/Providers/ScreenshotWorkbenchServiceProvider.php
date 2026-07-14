<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Support\MarketplaceFixture;

final class ScreenshotWorkbenchServiceProvider extends ServiceProvider
{
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

        File::ensureDirectoryExists(public_path('build/filament/assets'));
        File::put(
            public_path('build/filament/assets/theme.css'),
            "/* Screenshot workbench Filament theme entrypoint. */\n",
        );
        File::put(
            public_path('build/filament/manifest.json'),
            json_encode([
                'resources/css/filament/admin/theme.css' => [
                    'file' => 'assets/theme.css',
                    'isEntry' => true,
                    'src' => 'resources/css/filament/admin/theme.css',
                ],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );

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
}
