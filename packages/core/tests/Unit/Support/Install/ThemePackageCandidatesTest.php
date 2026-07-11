<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;

beforeEach(function (): void {
    CapellCore::clearPackages();
    (new Filesystem)->deleteDirectory(resource_path('views/capell/themes/capell-app/local-client'));
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory(resource_path('views/capell/themes/capell-app/local-client'));
});

it('returns static offline theme choices', function (): void {
    $options = new ThemePackageCandidates(new PackageWorkflowPlanner)
        ->optionsForSelection([]);

    expect($options)->toHaveKey('none')
        ->toHaveKey('default')
        ->toHaveKey('corporate')
        ->toHaveKey('saas')
        ->and($options['default'])->toBe('Default');
});

it('ignores installed foundation theme packages because default is built in', function (): void {
    CapellCore::registerPackage('capell-app/foundation-theme', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/foundation-theme')->themeKey = 'default';
    CapellCore::forcePackageInstalled('capell-app/foundation-theme');

    CapellCore::registerPackage('capell-app/theme-corporate', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-corporate')->themeKey = 'corporate';

    CapellCore::registerPackage('capell-app/theme-saas', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-saas')->themeKey = 'saas';

    $options = new ThemePackageCandidates(new PackageWorkflowPlanner)
        ->optionsForSelection(['capell-app/theme-corporate']);

    expect($options)->toHaveKey('default')
        ->toHaveKey('corporate')
        ->toHaveKey('saas')
        ->and(new ThemePackageCandidates(new PackageWorkflowPlanner)->packageNameForThemeKey('default'))->toBeNull();
});

it('includes downloadable theme packages from the install catalogue', function (): void {
    config([
        'capell-marketplace.marketplace.web_url' => null,
        'capell.marketplace_web_url' => 'https://capell-test.app',
    ]);

    CapellCore::registerPackage('capell-app/foundation-theme', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/foundation-theme')->themeKey = 'default';
    CapellCore::forcePackageInstalled('capell-app/foundation-theme');

    bindThemePackageCandidatesRemotePackages(Collection::make([
        [
            'name' => 'capell-app/theme-remote',
            'type' => PackageTypeEnum::Theme->value,
            'themeKey' => 'remote',
            'image_url' => '/docs/assets/marketplace/theme-remote.png',
        ],
        [
            'name' => 'capell-app/theme-corporate',
            'type' => PackageTypeEnum::Theme->value,
            'themeKey' => 'corporate',
        ],
    ]));

    $candidates = new ThemePackageCandidates(new PackageWorkflowPlanner);

    expect($candidates->optionDataForCatalogue())
        ->toHaveKey('default')
        ->toHaveKey('remote')
        ->toHaveKey('corporate')
        ->and($candidates->optionDataForCatalogue()['remote']->packageName)->toBe('capell-app/theme-remote')
        ->and($candidates->optionDataForCatalogue()['remote']->previewImageUrl)->toBe('https://capell-test.app/docs/assets/marketplace/theme-remote.png');
});

it('prefers locally discovered theme packages over cached catalogue options', function (): void {
    CapellCore::registerPackage('capell-app/theme-agency', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-agency')->themeKey = 'agency';

    bindThemePackageCandidatesRemotePackages(Collection::make([
        [
            'name' => 'capell-app/theme-agency-remote',
            'type' => PackageTypeEnum::Theme->value,
            'themeKey' => 'agency',
            'description' => 'Remote cached agency theme.',
        ],
    ]));

    $option = new ThemePackageCandidates(new PackageWorkflowPlanner)
        ->optionDataForCatalogue()['agency'] ?? null;
    $option = expectPresent($option);

    expect($option)->not->toBeNull()
        ->and($option->packageName)->toBe('capell-app/theme-agency');
});

it('reports selected theme keys only for installable theme candidates', function (): void {
    CapellCore::registerPackage('capell-app/theme-corporate', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-corporate')->themeKey = 'corporate';

    $candidates = new ThemePackageCandidates(new PackageWorkflowPlanner);

    expect($candidates->containsThemeKey('corporate', ['capell-app/theme-corporate']))->toBeTrue()
        ->and($candidates->containsThemeKey('saas', ['capell-app/theme-corporate']))->toBeTrue()
        ->and($candidates->containsThemeKey('missing', ['capell-app/theme-corporate']))->toBeFalse();
});

it('defaults catalogue selection to a local app theme when one exists', function (): void {
    writeThemePackageCandidatesLocalThemeManifest();

    $candidates = new ThemePackageCandidates(new PackageWorkflowPlanner);

    expect($candidates->defaultThemeKeyForCatalogue())->toBe('local-client');
});

it('falls back to default as the catalogue default without local themes', function (): void {
    $candidates = new ThemePackageCandidates(new PackageWorkflowPlanner);

    expect($candidates->defaultThemeKeyForCatalogue())->toBe(ThemePackageCandidates::DEFAULT_KEY);
});

it('keeps the default install choice as the selected input theme key', function (): void {
    $candidates = new ThemePackageCandidates(new PackageWorkflowPlanner);

    expect($candidates->inputThemeKey(ThemePackageCandidates::DEFAULT_KEY))->toBe(ThemePackageCandidates::DEFAULT_KEY)
        ->and($candidates->inputThemeKey(ThemePackageCandidates::LEGACY_FOUNDATION_KEY))->toBe(ThemePackageCandidates::DEFAULT_KEY)
        ->and($candidates->inputThemeKey(ThemePackageCandidates::NONE_KEY))->toBeNull()
        ->and($candidates->inputThemeKey('saas'))->toBe('saas');
});

it('resolves theme package names and local package manifests for catalogue installs', function (): void {
    $localThemeDirectory = sys_get_temp_dir() . '/capell-local-theme-package-' . bin2hex(random_bytes(6));
    mkdir($localThemeDirectory, recursive: true);

    file_put_contents($localThemeDirectory . '/composer.json', json_encode([
        'name' => 'capell-app/theme-local-client',
    ], JSON_THROW_ON_ERROR));

    file_put_contents($localThemeDirectory . '/capell.json', json_encode([
        'manifest-version' => 3,
        'name' => 'capell-app/theme-local-client',
        'kind' => 'theme',
        'themeKey' => 'local-client',
        'displayName' => 'Local Client',
        'description' => 'Client-owned local theme.',
        'marketplace' => [
            'screenshots' => [
                ['path' => '/themes/local-client.png'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    CapellCore::registerPackage('capell-app/theme-local-client', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-local-client')->themeKey = 'local-client';

    $candidates = new ThemePackageCandidates(new PackageWorkflowPlanner);
    $optionFromDirectory = new ReflectionMethod($candidates, 'themeOptionFromLocalDirectory');
    $pathRepositoryDirectories = new ReflectionMethod($candidates, 'directoriesForPathRepository');

    try {
        $option = $optionFromDirectory->invoke($candidates, $localThemeDirectory);
        $option = expectPresent($option);

        expect($candidates->packageNameForThemeKey('local-client'))->toBe('capell-app/theme-local-client')
            ->and($candidates->packageNameForThemeKey(ThemePackageCandidates::NONE_KEY))->toBeNull()
            ->and($candidates->packageNameForThemeKey(null))->toBeNull()
            ->and($option->key)->toBe('local-client')
            ->and($option->name)->toBe('Local Client')
            ->and($option->packageName)->toBe('capell-app/theme-local-client')
            ->and($option->previewImageUrl)->toBe('/themes/local-client.png')
            ->and($optionFromDirectory->invoke($candidates, sys_get_temp_dir()))->toBeNull()
            ->and($pathRepositoryDirectories->invoke($candidates, ['url' => ''], base_path()))->toBe([])
            ->and($pathRepositoryDirectories->invoke($candidates, ['url' => './missing-local-themes/*'], base_path()))->toBe([]);
    } finally {
        (new Filesystem)->deleteDirectory($localThemeDirectory);
    }
});

function bindThemePackageCandidatesRemotePackages(Collection $remotePackages): void
{
    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class($remotePackages) extends PluginPackagesFetcher
    {
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

function writeThemePackageCandidatesLocalThemeManifest(): void
{
    $files = new Filesystem;
    $path = resource_path('views/capell/themes/capell-app/local-client');

    $files->ensureDirectoryExists($path);
    $files->put($path . '/theme.json', json_encode([
        'key' => 'local-client',
        'name' => 'Local Client',
        'description' => 'Local client theme.',
        'package' => 'capell-app/local-client',
        'previewImage' => '/images/local-client.jpg',
        'presets' => [
            [
                'key' => 'default',
                'name' => 'Default',
                'description' => 'Default preset.',
                'previewImage' => '/images/local-client-default.jpg',
            ],
        ],
    ], JSON_THROW_ON_ERROR));
}
