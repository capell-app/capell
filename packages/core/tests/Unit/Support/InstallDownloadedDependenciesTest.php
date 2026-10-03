<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\RunInstallAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\InstallPlan;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\InstallStepExecutor;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Manifest\CapellManifestData;

function downloadedDependenciesInput(): InstallInputData
{
    return new InstallInputData(
        siteUrl: 'https://example.test',
        packages: [],
        languages: ['en'],
        demoContent: true,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        seedDefaultData: true,
        extraPackages: ['vendor/theme', 'vendor/layout-builder'],
    );
}

function registerDownloadedDependencies(): void
{
    foreach ([
        'vendor/block-library' => [],
        'vendor/layout-builder' => ['vendor/block-library'],
        'vendor/navigation' => [],
        'vendor/theme' => ['vendor/layout-builder', 'vendor/navigation'],
        'vendor/unrelated' => [],
    ] as $name => $requirements) {
        CapellCore::registerManifestPackage(CapellManifestData::fromArray(capellManifestV3Array(name: $name, overrides: [
            'dependencies' => ['requires' => $requirements, 'supports' => [], 'conflicts' => []],
            'commands' => ['install' => 'fixture:install', 'setup' => 'fixture:setup', 'demo' => 'fixture:demo', 'afterInstall' => 'fixture:after'],
        ])));
        CapellCore::forcePackageInstalled($name, false);
    }
}

it('refreshes the CLI plan after download and installs required lifecycles before the selected package', function (): void {
    CapellCore::clearPackages();
    $input = downloadedDependenciesInput();
    $initialPlan = InstallPlan::build($input);
    expect(array_column($initialPlan, 'key'))->not->toContain(InstallPlan::packageInstallStepKey('vendor/block-library'));
    $executed = [];
    app()->instance(InstallStepExecutor::class, new class($executed)
    {
        /** @param list<string> $executed */
        public function __construct(private array &$executed) {}

        public function execute(string $key, InstallRunState $state): InstallRunState
        {
            $this->executed[] = $key;
            if (InstallPlan::isPackageRequireStep($key)) {
                registerDownloadedDependencies();
            }

            if (InstallPlan::isPackageInstallStep($key)) {
                $package = CapellCore::getPackage(InstallPlan::packageNameFromStep($key));
                foreach ($package->getRequirements() as $required) {
                    throw_unless(CapellCore::isPackageInstalled($required), RuntimeException::class, 'Missing required package: ' . $required);
                }

                CapellCore::forcePackageInstalled($package->name);
            }

            return $state;
        }
    });

    $result = resolve(RunInstallAction::class)->runWithResult($input, new NullProgressReporter);

    $lifecycleKeys = array_values(array_filter($executed, fn (string $key): bool => InstallPlan::isPackageInstallStep($key) || InstallPlan::isPackageSetupStep($key) || InstallPlan::isPackageDemoStep($key) || InstallPlan::isPackageAfterInstallStep($key)));
    $expected = [];
    foreach ([InstallPlan::STEP_INSTALL_PACKAGE_PREFIX, InstallPlan::STEP_SETUP_PACKAGE_PREFIX, InstallPlan::STEP_DEMO_PACKAGE_PREFIX, InstallPlan::STEP_AFTER_INSTALL_PACKAGE_PREFIX] as $prefix) {
        foreach (['vendor/block-library', 'vendor/layout-builder', 'vendor/navigation', 'vendor/theme'] as $name) {
            $expected[] = $prefix . $name;
        }
    }

    expect($lifecycleKeys)->toBe($expected)
        ->and($result->completedSteps)->toBe($executed)
        ->and($executed)->toHaveCount(count(array_unique($executed)))
        ->and(CapellCore::isPackageInstalled('vendor/unrelated'))->toBeFalse();
});

it('keeps existing installed requirements and unrelated packages out of newly discovered work', function (): void {
    CapellCore::clearPackages();
    $input = downloadedDependenciesInput();
    $initialPlan = InstallPlan::build($input);
    registerDownloadedDependencies();
    CapellCore::forcePackageInstalled('vendor/block-library');

    $refreshed = InstallPlan::refreshPackageSteps($input, $initialPlan, [InstallPlan::packageRequireStepKey('vendor/theme')]);
    $keys = array_column($refreshed, 'key');
    expect($keys)->toContain(InstallPlan::packageInstallStepKey('vendor/layout-builder'), InstallPlan::packageInstallStepKey('vendor/navigation'))
        ->and($keys)->not->toContain(InstallPlan::packageInstallStepKey('vendor/block-library'), InstallPlan::packageInstallStepKey('vendor/unrelated'))
        ->and($refreshed[0])->toBe($initialPlan[0]);
});

it('keeps every selected install before setup when a theme manifest arrives after download', function (): void {
    CapellCore::clearPackages();
    CapellCore::registerPackage(name: 'vendor/frontend', setupCommand: 'fixture:setup', installCommand: 'fixture:install');
    $input = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: ['vendor/frontend'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        seedDefaultData: true,
        extraPackages: ['vendor/theme'],
    );
    $initialPlan = InstallPlan::build($input);
    registerDownloadedDependencies();
    CapellCore::getPackage('vendor/theme')->requirements[] = 'vendor/frontend';

    $keys = array_column(InstallPlan::refreshPackageSteps($input, $initialPlan, [InstallPlan::packageRequireStepKey('vendor/theme')]), 'key');
    expect(array_search(InstallPlan::packageInstallStepKey('vendor/theme'), $keys, true))
        ->toBeLessThan(array_search(InstallPlan::packageSetupStepKey('vendor/frontend'), $keys, true));
});

it('retains custom handle overrides when requesting a recorded install result', function (): void {
    $action = new class extends RunInstallAction
    {
        public bool $called = false;

        #[Override]
        public function handle(InstallInputData $inputData, ProgressReporter $reporter): void
        {
            $this->called = true;
        }
    };

    $result = $action->runWithResult(downloadedDependenciesInput(), new NullProgressReporter);
    expect($action->called)->toBeTrue()->and($result->selectedPackages)->toBe(['vendor/layout-builder', 'vendor/theme']);
});

it('inserts newly available package work before finalization when the initial plan had no package phases', function (): void {
    CapellCore::clearPackages();
    $input = new InstallInputData(
        siteUrl: 'https://example.test',
        packages: ['vendor/theme'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
        installDeveloperTooling: true,
    );
    $initialPlan = InstallPlan::build($input);
    registerDownloadedDependencies();

    $keys = array_column(InstallPlan::refreshPackageSteps($input, $initialPlan, [InstallPlan::STEP_INSTALL_DEVELOPER_TOOLING]), 'key');
    expect($keys)->toContain(InstallPlan::packageInstallStepKey('vendor/theme'))
        ->and(array_search(InstallPlan::packageInstallStepKey('vendor/theme'), $keys, true))->toBeLessThan(array_search(InstallPlan::STEP_RUN_MIGRATIONS_POST, $keys, true));
});
