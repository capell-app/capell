<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Contracts\Extenders\AdminPanelExtender;
use Capell\Admin\Filament\Pages\Extensions\Tables\ExtensionRecord;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Support\AdminSurfaceContributionRegistry;
use Capell\Admin\Support\InstalledPanelRuntime;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LatePanelRuntimeProvider;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateRuntimePage;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\LateSecurityMiddleware;
use Capell\Admin\Tests\Fixtures\Filament\Plugin\UnrelatedLegacyAvailabilityProvider;
use Capell\Core\Actions\DisablePackageAction;
use Capell\Core\Actions\EnablePackageAction;
use Capell\Core\Actions\InstallPackageAction;
use Capell\Core\Actions\RuntimeRefresh\RefreshInstalledPackageRuntimeAction;
use Capell\Core\Actions\UninstallPackageAction;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

final class InstalledPanelRuntimeAvailabilityTest extends AdminTestCase
{
    #[TestWith(['install', true])]
    #[TestWith(['enable', true])]
    #[TestWith(['refresh', true])]
    #[TestWith(['install', false])]
    #[TestWith(['enable', false])]
    #[TestWith(['refresh', false])]
    public function test_unrelated_package_activation_remains_available_after_panel_only_failure(string $operation, bool $adopter): void
    {
        $logger = $this->failOnlyAdminPanel();
        $package = $this->registerUnrelatedPackage($adopter);
        if ($operation === 'enable') {
            CapellCore::markPackageDisabled($package->name);
        } elseif ($operation === 'refresh') {
            CapellCore::markPackageInstalled($package->name);
        }

        match ($operation) {
            'install' => InstallPackageAction::run($package),
            'enable' => EnablePackageAction::run($package),
            'refresh' => RefreshInstalledPackageRuntimeAction::run($package),
            default => throw new RuntimeException('Unknown lifecycle operation.'),
        };

        $this->assertSame('enabled', $this->persistedStatus($package->name));
        $this->assertTrue(CapellCore::isPackageEnabled($package->name));
        $pages = resolve(AdminSurfaceContributionRegistry::class)->pages();
        if ($adopter || $operation !== 'enable') {
            $this->assertContains(LateRuntimePage::class, $pages);
        } else {
            $this->assertNotContains(LateRuntimePage::class, $pages);
        }

        // Legacy enable has no runtime event; the table still synchronises its panel.
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        CapellAdminPlugin::make()->synchronizeCurrentPanelAdminSurface();
        Notification::assertNotified(Notification::make()
            ->title(__('capell-admin::message.extension_panel_refresh_deferred', ['panel' => 'admin']))
            ->body(__('capell-core::runtime-refresh.application_unavailable'))
            ->warning()
            ->persistent());
        $logger->shouldHaveReceived('warning')->with(
            __('capell-admin::message.extension_panel_refresh_deferred', ['panel' => 'admin']),
            ['panel' => 'admin'],
        );
        $this->assertNotContains(LateRuntimePage::class, Filament::getPanel('admin')->getPages());
        $this->assertNotContains(LateSecurityMiddleware::class, Filament::getPanel('admin')->getAuthMiddleware());
        $this->assertFailureRemainsContained();
    }

    public function test_unrelated_providerless_install_does_not_persist_failed_state(): void
    {
        $this->failOnlyAdminPanel();
        CapellCore::registerPackage('test/unrelated-providerless');
        try {
            InstallPackageAction::run(CapellCore::getPackage('test/unrelated-providerless'));
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('fresh application', $runtimeException->getMessage());
        }

        $this->assertSame('enabled', $this->persistedStatus('test/unrelated-providerless'));
        $this->assertFailureRemainsContained();
    }

    #[TestWith(['disable'])]
    #[TestWith(['uninstall'])]
    public function test_unrelated_deactivation_and_table_refresh_remain_available(string $operation): void
    {
        $this->failOnlyAdminPanel();
        CapellCore::registerPackage('test/unrelated-deactivation');
        CapellCore::markPackageInstalled('test/unrelated-deactivation');
        $package = CapellCore::getPackage('test/unrelated-deactivation');
        if ($operation === 'disable') {
            DisablePackageAction::run($package);
        } else {
            UninstallPackageAction::run($package);
        }

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        ExtensionRecord::refreshTable(new class extends Component {});
        $this->assertFalse(CapellCore::isPackageEnabled($package->name));
        $this->assertSame($operation === 'disable' ? 'disabled' : null, $this->persistedStatus($package->name));
        $this->assertFalse(CapellCore::isPackageInstalled($package->name));
        $this->assertFailureRemainsContained();
    }

    public function test_unrelated_bundle_install_remains_available(): void
    {
        $this->failOnlyAdminPanel();
        $member = $this->registerUnrelatedPackage(true);
        CapellCore::registerPackage('test/unrelated-bundle');
        $bundle = CapellCore::getPackage('test/unrelated-bundle');
        $bundle->kind = 'bundle';
        $bundle->requirements = [$member->name];
        InstallPackageAction::run($bundle);
        $this->assertSame('enabled', $this->persistedStatus($member->name));
        $this->assertSame('enabled', $this->persistedStatus($bundle->name));
        $this->assertFailureRemainsContained();
    }

    #[TestWith(['install'])]
    #[TestWith(['enable'])]
    #[TestWith(['refresh'])]
    public function test_the_package_whose_panel_wiring_failed_still_refuses_activation(string $operation): void
    {
        $this->failOnlyAdminPanel();
        $package = CapellCore::getPackage('test/original-panel-failure');
        try {
            match ($operation) {
                'install' => InstallPackageAction::run($package),
                'enable' => EnablePackageAction::run($package),
                'refresh' => RefreshInstalledPackageRuntimeAction::run($package),
                default => throw new RuntimeException('Unknown lifecycle operation.'),
            };
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('fresh application', $runtimeException->getMessage());
            $this->assertFailureRemainsContained();

            return;
        }

        $this->fail('Failed package activation must still require a fresh application.');
    }

    private function registerUnrelatedPackage(bool $adopter): PackageData
    {
        $provider = $adopter ? LatePanelRuntimeProvider::class : UnrelatedLegacyAvailabilityProvider::class;
        CapellCore::registerPackage('test/panel-runtime', serviceProviderClass: $provider);
        $this->assertSame($adopter, InstalledRuntimeLifecycle::adopts($provider));
        app()->register($provider);

        return CapellCore::getPackage('test/panel-runtime');
    }

    private function failOnlyAdminPanel(): MockInterface
    {
        $extender = new class implements AdminPanelExtender
        {
            #[Override]
            public function extend(Panel $panel): void
            {
                throw new RuntimeException('Deliberate panel-only failure.');
            }
        };
        app()->instance('runtime.availability-failure', $extender);
        app()->tag(['runtime.availability-failure'], AdminPanelExtender::TAG);
        CapellCore::registerPackage('test/original-panel-failure');
        $logger = Log::spy();
        try {
            InstallPackageAction::run(CapellCore::getPackage('test/original-panel-failure'));
            $this->fail('The package whose panel wiring fails must not report success.');
        } catch (RuntimeException $runtimeException) {
            $this->assertSame('Deliberate panel-only failure.', $runtimeException->getMessage());
        }

        $this->assertSame('failed', $this->persistedStatus('test/original-panel-failure'));
        $logger->shouldHaveReceived('error')->once();
        $this->assertTrue(resolve(InstalledPanelRuntime::class)->isUnavailable('admin'));
        $this->assertFalse(resolve(InstalledRuntimeLifecycle::class)->isUnavailable());

        return $logger;
    }

    private function assertFailureRemainsContained(): void
    {
        $this->assertSame('failed', $this->persistedStatus('test/original-panel-failure'));
        $this->assertFalse(CapellCore::isPackageEnabled('test/original-panel-failure'));
        $this->assertTrue(resolve(InstalledPanelRuntime::class)->isUnavailable('admin'));
        $this->assertFalse(resolve(InstalledRuntimeLifecycle::class)->isUnavailable());
        $this->get('/admin/login')->assertStatus(503);
        try {
            resolve(InstalledPanelRuntime::class)->extend(Filament::getPanel('admin'));
            $this->fail('A denied panel must still refuse direct activation.');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('fresh application', $runtimeException->getMessage());
        }
    }

    private function persistedStatus(string $package): ?string
    {
        return CapellExtension::query()->where('composer_name', $package)->first()?->status->value;
    }
}
