<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Commands;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Core\Facades\CapellCore;
use Filament\Panel;
use Filament\PanelRegistry;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Override;
use ReflectionClass;

final class AdminAssetPublicationTest extends AdminTestCase
{
    public function test_publishes_all_admin_assets_before_installing_or_integrating_the_panel(): void
    {
        CapellCore::markPackageUninstalled(AdminServiceProvider::$packageName);

        $this->assertFalse(CapellCore::getPackage(AdminServiceProvider::$packageName)->isInstalled());

        $panel = Panel::make()->id('plain-admin')->default()->path('admin');
        resolve(PanelRegistry::class)->register($panel);

        $this->assertFalse($panel->hasPlugin(CapellAdminPlugin::ID));

        $this->assertAdminAssetsArePublished();
    }

    public function test_publishes_the_same_assets_after_installed_plugin_registration(): void
    {
        CapellCore::markPackageInstalled(AdminServiceProvider::$packageName);

        $panel = Panel::make()->id('integrated-admin')->default()->path('admin');
        $panel->plugin(CapellAdminPlugin::make());

        resolve(PanelRegistry::class)->register($panel);

        $this->assertAdminAssetsArePublished();
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return array_values(array_filter(
            parent::getPackageProviders($app),
            static fn (string $provider): bool => $provider !== AdminPanelProvider::class,
        ));
    }

    private function assertAdminAssetsArePublished(): void
    {
        $originalPublicPath = public_path();
        $publicPath = storage_path('framework/testing/admin-assets-public-' . Str::uuid());
        app()->usePublicPath($publicPath);

        try {
            $this->assertSame(0, Artisan::call('filament:assets', ['--no-interaction' => true]));

            $providerFile = new ReflectionClass(AdminServiceProvider::class)->getFileName();
            $this->assertIsString($providerFile);
            $packagePath = dirname($providerFile, 3);
            $assets = [
                'css/capell-app/admin/admin-layer-order.css' => '/resources/css/filament/admin/layer-order.css',
                'js/capell-admin/rich-content-plugins/highlight.js' => '/publishes/build/js/filament/rich-content-plugins/highlight.js',
                'js/capell-admin/components/capell-agent-admin.js' => '/publishes/build/js/agent/admin-bridge.js',
                'js/capell-admin/components/html-code-editor.js' => '/publishes/build/js/components/html-code-editor.js',
                'js/capell-admin/components/capell-keyboard-shortcuts.js' => '/publishes/build/js/components/keyboard-shortcuts.js',
                'js/capell-admin/components/capell-content-lock-heartbeat.js' => '/publishes/build/js/components/content-lock-heartbeat.js',
            ];

            foreach ($assets as $publicRelativePath => $sourceRelativePath) {
                $sourcePath = $packagePath . $sourceRelativePath;
                $publishedPath = public_path($publicRelativePath);

                $this->assertFileExists($sourcePath);
                $this->assertFileExists($publishedPath);
                $this->assertSame(File::get($sourcePath), File::get($publishedPath));
            }

            $this->assertCount(4, FilamentAsset::getAlpineComponents(['capell-admin']));
            $this->assertCount(1, FilamentAsset::getScripts(['capell-admin']));
            $this->assertCount(1, FilamentAsset::getStyles([AdminServiceProvider::$packageName]));
        } finally {
            File::deleteDirectory($publicPath);
            app()->usePublicPath($originalPublicPath);
        }
    }
}
