<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\FailingBootstrapPanelExtender;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateRuntimeResource;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateTopologyPanelExtender;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateTopologyRuntimeProvider;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Contracts\AdminPermissionSynchronizer;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Install\Cli\FilamentAdminInstallPreflight;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Tests\Support\Fakes\FakeConsoleKernel;
use Capell\Tests\Support\Fakes\FakeProcessFactory;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Override;
use RuntimeException;

final class InstalledPanelRuntimeColdInstallTest extends AdminTestCase
{
    public function test_cold_panel_install_defers_topology_and_allows_subsequent_package_installs(): void
    {
        $this->assertTrue(app()->isBooted());
        $this->assertSame([], Filament::getPanels());
        // Already active package wiring is replayed by a panel scaffolded after boot.
        app()->tag([LateTopologyPanelExtender::class], AdminPanelExtender::TAG);
        $this->preparePanelScaffolding('ColdInstallPanelProvider');
        $logger = Log::spy();
        $errors = [];

        $this->assertTrue($this->installPanel($errors), implode("\n", $errors));
        $this->assertSame([], $errors);
        $panel = Filament::getPanel('admin');
        $this->assertContains(LateRuntimeResource::class, $panel->getResources());
        $runtime = resolve(InstalledPanelRuntime::class);
        $this->assertTrue($runtime->isUnavailable('admin'));
        $this->assertTrue($runtime->requiresFreshApplication('admin'));
        $this->assertFalse(resolve(AdminPermissionSynchronizer::class)->hasBootedPanel());
        $this->assertSame([], resolve(InstalledRuntimeLifecycle::class)->failures());
        $logger->shouldHaveReceived('warning')->with(
            __('capell-admin::message.extension_panel_refresh_deferred', ['panel' => 'admin']),
            ['panel' => 'admin'],
        );

        CapellCore::registerPackage('test/panel-topology');
        CapellCore::forcePackageInstalled('test/panel-topology', false);
        app()->register(LateTopologyRuntimeProvider::class);
        $this->assertFalse(CapellCore::isPackageEnabled('test/panel-topology'));
        $this->assertCount(1, $this->topologyExtenders());
        InstallPackageAction::run(CapellCore::getPackage('test/panel-topology'));
        $this->assertCount(2, $this->topologyExtenders());
        CapellCore::registerPackage('test/after-topology');
        InstallPackageAction::run(CapellCore::getPackage('test/after-topology'));

        foreach (['test/panel-topology', 'test/after-topology'] as $package) {
            $this->assertSame(ExtensionStatusEnum::Enabled, CapellExtension::query()->where('composer_name', $package)->first()?->status);
            $this->assertTrue(CapellCore::isPackageEnabled($package));
            $this->assertFalse(resolve(InstalledRuntimeLifecycle::class)->packageFailed($package));
        }

        $this->assertFalse(resolve(InstalledRuntimeLifecycle::class)->isUnavailable());
        $this->assertSame([], resolve(InstalledRuntimeLifecycle::class)->failures());
        Route::get('/admin/cold-install-probe', static fn (): string => 'panel')
            ->middleware($panel->getMiddleware())->name('filament.admin.cold-install-probe');
        $this->get('/admin/cold-install-probe')->assertStatus(503);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fresh application');
        $runtime->extend($panel);
    }

    public function test_cold_panel_install_still_rejects_a_failing_extender(): void
    {
        app()->tag([FailingBootstrapPanelExtender::class], AdminPanelExtender::TAG);
        $this->preparePanelScaffolding('FailingColdInstallPanelProvider');
        $errors = [];

        $this->assertFalse($this->installPanel($errors));
        $this->assertSame(['Cold bootstrap panel failure.'], $errors);
        $this->assertTrue(resolve(InstalledPanelRuntime::class)->isUnavailable('admin'));
        $this->assertFalse(resolve(InstalledPanelRuntime::class)->requiresFreshApplication('admin'));
        $this->assertNotEmpty(resolve(InstalledRuntimeLifecycle::class)->failures());
    }

    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return array_values(array_diff(parent::getPackageProviders($app), [AdminPanelProvider::class]));
    }

    private function preparePanelScaffolding(string $provider): void
    {
        $originalBasePath = app()->basePath();
        $temporaryBasePath = sys_get_temp_dir() . '/capell-cold-panel-install-' . bin2hex(random_bytes(8));
        File::ensureDirectoryExists($temporaryBasePath);
        app()->setBasePath($temporaryBasePath);
        $this->beforeApplicationDestroyed(static function () use ($originalBasePath, $temporaryBasePath): void {
            app()->setBasePath($originalBasePath);
            File::deleteDirectory($temporaryBasePath);
            FakeProcessFactory::verifyBound();
        });

        FakeConsoleKernel::bind();
        Artisan::clearResolvedInstances();
        FakeProcessFactory::bind()
            ->expect([PHP_BINARY, 'artisan', 'filament:install', '--panels', '--no-interaction'])
            ->push(onRun: static function () use ($provider): void {
                File::ensureDirectoryExists(app_path('Providers/Filament'));
                File::put(
                    app_path('Providers/Filament/' . $provider . '.php'),
                    '<?php namespace App\\Providers\\Filament; class ' . $provider . ' extends \\Capell\\Admin\\Providers\\Filament\\AdminPanelProvider {}',
                );
            });
    }

    /** @return list<LateTopologyPanelExtender> */
    private function topologyExtenders(): array
    {
        $extenders = [];
        foreach (app()->tagged(AdminPanelExtender::TAG) as $extender) {
            if ($extender instanceof LateTopologyPanelExtender) {
                $extenders[] = $extender;
            }
        }

        return $extenders;
    }

    /** @param list<string> $errors */
    private function installPanel(array &$errors): bool
    {
        return (new FilamentAdminInstallPreflight)->ensureReady(
            packages: collect(['capell-app/admin' => CapellCore::getPackage('capell-app/admin')]),
            interactive: false,
            useFreshDemoDefaults: false,
            reporter: new NullProgressReporter,
            writeError: static function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
        );
    }
}
