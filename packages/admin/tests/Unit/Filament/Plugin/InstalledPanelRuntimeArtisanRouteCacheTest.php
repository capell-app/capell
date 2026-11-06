<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Tests\AdminTestCase;
use Capell\Core\Support\TableName;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Livewire\Mechanisms\FrontendAssets\FrontendAssets;
use Symfony\Component\Process\Process;

/**
 * The route cache `artisan route:cache` writes from a freshly booted application keeps every route's
 * recorded ownership: admin routes stay held to the panel, public routes stay public.
 */
final class InstalledPanelRuntimeArtisanRouteCacheTest extends AdminTestCase
{
    public function test_routes_cached_by_a_fresh_application_keep_their_ownership(): void
    {
        $this->loadRouteCacheFromAFreshApplication();

        // Core's admin route files and the declared icon picker stay owned by the admin panel.
        $this->get(route('capell-admin.extension-asset'))->assertRedirect('/admin/login');
        $this->get(route('guava-icon-picker.index'))->assertRedirect('/admin/login');
        // Filament's global export download still sends the visitor on to the originating panel.
        DB::table(TableName::resolve('admin_transfer_origins'))->insert(['transfer_type' => 'export', 'transfer_key' => '7', 'panel' => 'admin', 'host' => 'localhost']);
        $this->get(route('filament.exports.download', ['export' => 7, 'format' => 'csv']))
            ->assertRedirectContains('/admin/capell/exports/7/download');
        // Livewire's script stays public.
        $script = collect(Route::getRoutes()->getRoutes())
            ->first(static fn (RoutingRoute $route): bool => $route->getActionName() === FrontendAssets::class . '@returnJavaScriptAsFile');
        $this->assertInstanceOf(RoutingRoute::class, $script);
        $this->get('/' . $script->uri())->assertOk();
    }

    private function loadRouteCacheFromAFreshApplication(): void
    {
        $file = storage_path('framework/testing-artisan-routes-' . bin2hex(random_bytes(6)) . '.php');
        File::ensureDirectoryExists(dirname($file));
        $root = dirname(__DIR__, 6);
        $process = new Process([PHP_BINARY, $root . '/vendor/bin/testbench', 'route:cache'], $root, ['APP_ROUTES_CACHE' => $file]);
        $process->setTimeout(300)->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() . $process->getOutput());

        try {
            // The cache file loads its compiled routes into the application's router, as a cached boot does.
            require $file;
        } finally {
            File::delete($file);
        }
    }
}
