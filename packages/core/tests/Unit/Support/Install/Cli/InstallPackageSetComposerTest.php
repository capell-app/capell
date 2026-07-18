<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\Cli\InstallPackageSetComposer;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

beforeEach(function (): void {
    CapellCore::clearPackages();
});

it('preserves console package option precedence for demo and install-time package composition', function (): void {
    $composer = installPackageSetComposer();

    expect($composer->shouldIncludeDemoPackagesAfterSelection(
        interactive: false,
        packagesOption: null,
        packageModeOption: null,
        allPackages: false,
        useFreshDemoPackageDefaults: false,
    ))->toBeTrue()
        ->and($composer->shouldIncludeDemoPackagesAfterSelection(
            interactive: true,
            packagesOption: null,
            packageModeOption: null,
            allPackages: false,
            useFreshDemoPackageDefaults: false,
        ))->toBeFalse()
        ->and($composer->shouldIncludeDemoPackagesAfterSelection(
            interactive: true,
            packagesOption: '',
            packageModeOption: null,
            allPackages: false,
            useFreshDemoPackageDefaults: false,
        ))->toBeTrue()
        ->and($composer->installTimePackageNames(
            selectedPackageNames: ['vendor/not-trusted'],
            packageMode: 'all',
            allPackages: false,
            useFreshDemoPackageDefaults: false,
        ))->toBe([
            'capell-app/admin',
            'capell-app/frontend',
            'capell-app/marketplace',
            'capell-app/welcome-tour',
        ]);
});

it('normalizes legacy theme keys and retains the unknown-theme error contract', function (): void {
    $composer = installPackageSetComposer();
    $errors = [];

    expect($composer->resolveThemeSelection(
        themeOption: 'foundation',
        interactive: false,
        useFreshDemoDefaults: false,
        writeError: function (string $message) use (&$errors): void {
            $errors[] = $message;
        },
    ))->toBe([ThemePackageCandidates::DEFAULT_KEY, null])
        ->and($composer->resolveThemeSelection(
            themeOption: 'missing-theme',
            interactive: false,
            useFreshDemoDefaults: false,
            writeError: function (string $message) use (&$errors): void {
                $errors[] = $message;
            },
        ))->toBe([null, SymfonyCommand::FAILURE])
        ->and($errors)->toHaveCount(1)
        ->and($errors[0])->toStartWith('Unknown theme [missing-theme]. Available themes: ');
});

it('adds demo packages with their requirements in workflow order', function (): void {
    CapellCore::registerPackage('vendor/site');
    CapellCore::getPackage('vendor/site')->sort = 30;

    CapellCore::registerPackage('vendor/demo-dependency');
    CapellCore::getPackage('vendor/demo-dependency')->sort = 10;

    CapellCore::registerPackage('vendor/demo-package');
    CapellCore::getPackage('vendor/demo-package')->sort = 20;
    CapellCore::getPackage('vendor/demo-package')->demo = true;
    CapellCore::getPackage('vendor/demo-package')->requirements = ['vendor/demo-dependency'];

    $packages = installPackageSetComposer()->includeDemoPackages(
        collect(['vendor/site' => CapellCore::getPackage('vendor/site')]),
        includeInstalledRequirements: false,
    );

    expect($packages->keys()->all())->toBe([
        'vendor/demo-dependency',
        'vendor/demo-package',
        'vendor/site',
    ]);
});

it('adds registered theme packages to the workflow without extra packages', function (): void {
    CapellCore::registerPackage('vendor/theme-dependency');
    CapellCore::getPackage('vendor/theme-dependency')->sort = 10;

    CapellCore::registerPackage('vendor/site');
    CapellCore::getPackage('vendor/site')->sort = 20;

    CapellCore::registerPackage('capell-app/theme-client', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-client')->sort = 30;
    CapellCore::getPackage('capell-app/theme-client')->themeKey = 'client';
    CapellCore::getPackage('capell-app/theme-client')->requirements = ['vendor/theme-dependency'];

    [$packages, $extraPackages] = installPackageSetComposer()->includeSelectedThemePackage(
        collect(['vendor/site' => CapellCore::getPackage('vendor/site')]),
        selectedThemeKey: 'client',
        includeInstalledRequirements: false,
    );
    [$alreadySelectedPackages, $alreadySelectedExtraPackages] = installPackageSetComposer()->includeSelectedThemePackage(
        $packages,
        selectedThemeKey: 'client',
        includeInstalledRequirements: false,
    );

    expect($packages->keys()->all())->toBe([
        'vendor/theme-dependency',
        'vendor/site',
        'capell-app/theme-client',
    ])
        ->and($extraPackages)->toBe([])
        ->and($alreadySelectedPackages)->toBe($packages)
        ->and($alreadySelectedExtraPackages)->toBe([]);
});

it('returns downloadable theme packages as extra install packages', function (): void {
    bindInstallPackageSetComposerRemotePackages(collect([
        [
            'name' => 'capell-app/theme-remote',
            'type' => PackageTypeEnum::Theme->value,
            'themeKey' => 'remote',
        ],
    ]));

    $selectedPackages = collect();
    [$packages, $extraPackages] = installPackageSetComposer()->includeSelectedThemePackage(
        $selectedPackages,
        selectedThemeKey: 'remote',
        includeInstalledRequirements: false,
    );

    expect($packages)->toBe($selectedPackages)
        ->and($extraPackages)->toBe(['capell-app/theme-remote']);
});

function installPackageSetComposer(): InstallPackageSetComposer
{
    $planner = new PackageWorkflowPlanner;

    return new InstallPackageSetComposer(
        $planner,
        new ThemePackageCandidates($planner),
    );
}

/**
 * @param  Collection<int, array<string, mixed>>  $remotePackages
 */
function bindInstallPackageSetComposerRemotePackages(Collection $remotePackages): void
{
    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class($remotePackages) extends PluginPackagesFetcher
    {
        /** @param  Collection<int, array<string, mixed>>  $remotePackages */
        public function __construct(private readonly Collection $remotePackages) {}

        public function fetch(bool $force = false): Collection
        {
            return $this->remotePackages;
        }

        public function getCached(): Collection
        {
            return $this->remotePackages;
        }
    });
}
