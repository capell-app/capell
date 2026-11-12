<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Unit\Filament\Plugin;

use Capell\Admin\Providers\AdminServiceProvider;
use Capell\Admin\Providers\Filament\AdminPanelProvider;
use Capell\Admin\Tests\AdminTestCase;
use Capell\Core\Actions\Install\InstallDeveloperToolingAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Packages\PackageSurfaceRegistrar;
use Capell\Tests\AbstractTestCase;
use Illuminate\Support\Facades\File;
use LogicException;
use Override;

/** The package installer registers the admin package it has just installed in its own, already-booted process. */
final class AdminPackageInstalledAfterBootTest extends AbstractTestCase
{
    private ?string $originalManifest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $manifest = base_path('bootstrap/cache/packages.php');
        $this->originalManifest = is_file($manifest) ? (string) file_get_contents($manifest) : null;
        File::ensureDirectoryExists(dirname($manifest));
        file_put_contents($manifest, '<?php return ' . var_export(['capell-app/admin' => ['providers' => [AdminServiceProvider::class]]], true) . ';');
        // The developer-tooling step's Composer work is outside this proof; its provider registration is not.
        app()->instance(InstallDeveloperToolingAction::class, new class extends InstallDeveloperToolingAction
        {
            #[Override]
            public function handle(ProgressReporter $reporter, bool $configureBoost): void {}
        });
    }

    protected function tearDown(): void
    {
        $manifest = base_path('bootstrap/cache/packages.php');
        $this->originalManifest === null ? @unlink($manifest) : file_put_contents($manifest, $this->originalManifest);
        putenv('LARAVEL_OCTANE');
        parent::tearDown();
    }

    public function test_the_installer_registers_the_newly_installed_admin_package_after_boot(): void
    {
        $this->assertTrue(app()->isBooted());
        $this->assertFalse(app()->providerIsLoaded(AdminServiceProvider::class));

        $this->runDeveloperToolingStep();

        $this->assertTrue(app()->providerIsLoaded(AdminServiceProvider::class));
        $this->assertFalse(resolve(PackageSurfaceRegistrar::class)->isInstallingPackages());
    }

    /** An Octane worker keeps serving requests from this application, so it may not register the admin package late. */
    public function test_the_installer_may_not_register_the_admin_package_after_boot_under_octane(): void
    {
        putenv('LARAVEL_OCTANE=1');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The admin package must be registered before the application boots so Livewire updates honour middleware responses.');

        $this->runDeveloperToolingStep();
    }

    /** @return list<class-string> */
    #[Override]
    protected function getPackageProviders(mixed $app): array
    {
        return array_values(array_filter(
            [...parent::getDefaultPackageProviders(), ...AdminTestCase::adminPackageProviders()],
            static fn (string $provider): bool => ! in_array($provider, [AdminServiceProvider::class, AdminPanelProvider::class], true),
        ));
    }

    #[Override]
    protected function getPackageServiceName(): string
    {
        return 'capell-admin';
    }

    private function runDeveloperToolingStep(): void
    {
        resolve(InstallStepExecutor::class)->execute(InstallPlan::STEP_INSTALL_DEVELOPER_TOOLING, new InstallRunState(
            new InstallInputData(
                siteUrl: 'https://example.com',
                packages: ['capell-app/admin'],
                languages: ['en'],
                demoContent: false,
                cachesToClear: [],
                generateSitemap: false,
                generateStaticSite: false,
            ),
            new class implements ProgressReporter
            {
                public function step(string $label): void {}

                public function report(string $message): void {}

                public function error(string $message): void {}
            },
        ));
    }
}
