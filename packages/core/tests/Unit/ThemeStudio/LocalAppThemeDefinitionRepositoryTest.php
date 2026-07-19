<?php

declare(strict_types=1);

use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Install\PackageWorkflowPlanner;
use Capell\Core\Support\Install\ThemePackageCandidates;
use Capell\Core\Support\Plugins\PluginPackagesFetcher;
use Capell\Core\ThemeStudio\Data\ThemeDefinitionData;
use Capell\Core\ThemeStudio\Data\ThemeFrontendBuildAssetsData;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionMapper;
use Capell\Core\ThemeStudio\Discovery\LocalAppThemeDefinitionRepository;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->files = new Filesystem;
    $this->fixturePath = resource_path('views/capell/themes/capell-app/local-test');
    $this->cachePath = base_path('bootstrap/cache/capell-local-app-themes.php');

    $this->files->deleteDirectory($this->fixturePath);
    $this->files->delete($this->cachePath);
    CapellCore::clearPackages();
});

afterEach(function (): void {
    $this->files->deleteDirectory($this->fixturePath);
    $this->files->delete($this->cachePath);
});

it('maps local app theme manifests to theme definitions', function (): void {
    $definition = (new LocalAppThemeDefinitionMapper)->fromManifest(localAppThemeManifest([
        'assets' => [' resources/css/capell/frontend.css '],
        'frontend' => [
            'assets' => [
                'cssSource' => 'resources/css/theme-local-test.css',
                'cssBuildInput' => 'resources/css/capell/themes/local-test.css',
                'condition' => 'theme-css:local-test',
            ],
        ],
    ]));

    expect($definition)->toBeInstanceOf(ThemeDefinitionData::class)
        ->and($definition->key)->toBe('local-test')
        ->and($definition->presets)->toHaveCount(1)
        ->and($definition->presets[0]->key)->toBe('launch')
        ->and($definition->includedSections)->toBe(['hero', 'features'])
        ->and($definition->assets)->toBe(['resources/css/capell/frontend.css'])
        ->and($definition->frontendBuildAssets())->toBeInstanceOf(ThemeFrontendBuildAssetsData::class)
        ->and($definition->frontendBuildAssets()?->cssSource)->toBe('resources/css/theme-local-test.css')
        ->and($definition->frontendBuildAssets()?->cssBuildInput)->toBe('resources/css/capell/themes/local-test.css')
        ->and($definition->frontendBuildAssets()?->condition)->toBe('theme-css:local-test')
        ->and($definition->presets[0]->values['primaryColor'])->toBe('#123456');
});

it('skips invalid local app theme manifests and logs a warning', function (): void {
    Log::spy();

    writeLocalThemeManifest('{broken-json');

    $definitions = resolve(LocalAppThemeDefinitionRepository::class)->discover();

    expect($definitions)->toBe([]);

    Log::getFacadeRoot()->shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Skipping invalid local Capell app theme manifest.'
            && str_ends_with((string) $context['path'], 'resources/views/capell/themes/capell-app/local-test/theme.json'));
});

it('skips local app theme manifests with missing required fields and logs a warning', function (): void {
    Log::spy();

    writeLocalThemeManifest(json_encode([
        ...localAppThemeManifest(),
        'name' => null,
    ], JSON_THROW_ON_ERROR));

    $definitions = resolve(LocalAppThemeDefinitionRepository::class)->discover();

    expect($definitions)->toBe([]);

    Log::getFacadeRoot()->shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Skipping invalid local Capell app theme manifest.'
            && $context['message'] === 'Theme manifest is missing required string field [name].');
});

it('skips local app theme manifests with invalid runtime values', function (): void {
    Log::spy();

    writeLocalThemeManifest(json_encode([
        ...localAppThemeManifest(),
        'runtime' => 'inertiajs',
    ], JSON_THROW_ON_ERROR));

    $definitions = resolve(LocalAppThemeDefinitionRepository::class)->discover();

    expect($definitions)->toBe([]);

    Log::getFacadeRoot()->shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Skipping invalid local Capell app theme manifest.'
            && $context['message'] === 'Theme manifest has invalid runtime [inertiajs].');
});

it('writes reads and clears the local app theme cache file', function (): void {
    writeLocalThemeManifest(json_encode(localAppThemeManifest(), JSON_THROW_ON_ERROR));

    $repository = resolve(LocalAppThemeDefinitionRepository::class);
    $repository->writeCache();

    $this->files->deleteDirectory($this->fixturePath);

    expect($this->files->exists($this->cachePath))->toBeTrue()
        ->and($repository->all())->toHaveKey('local-test')
        ->and($repository->clearCache())->toBeTrue()
        ->and($this->files->exists($this->cachePath))->toBeFalse();
});

it('ignores wrong-shape local app theme cache files and falls back to discovery', function (): void {
    Log::spy();

    writeLocalThemeManifest(json_encode(localAppThemeManifest(), JSON_THROW_ON_ERROR));

    $this->files->ensureDirectoryExists(dirname($this->cachePath));
    $this->files->put($this->cachePath, '<?php return "not an array";');

    $definitions = resolve(LocalAppThemeDefinitionRepository::class)->all();

    expect($definitions)->toHaveKey('local-test')
        ->and($this->files->exists($this->cachePath))->toBeFalse();

    Log::getFacadeRoot()->shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Ignoring invalid local Capell app theme cache file.'
            && $context['path'] === $this->cachePath
            && $context['message'] === 'Cache file did not return an array.');
});

it('ignores corrupt local app theme cache files and falls back to discovery', function (): void {
    Log::spy();

    writeLocalThemeManifest(json_encode(localAppThemeManifest(), JSON_THROW_ON_ERROR));

    $this->files->ensureDirectoryExists(dirname($this->cachePath));
    $this->files->put($this->cachePath, '<?php this is not valid php');

    $definitions = resolve(LocalAppThemeDefinitionRepository::class)->all();

    expect($definitions)->toHaveKey('local-test')
        ->and($this->files->exists($this->cachePath))->toBeFalse();

    Log::getFacadeRoot()->shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Ignoring invalid local Capell app theme cache file.'
            && $context['path'] === $this->cachePath);
});

it('prefers local app theme definitions over downloadable catalogue options', function (): void {
    writeLocalThemeManifest(json_encode(localAppThemeManifest([
        'key' => 'remote',
        'name' => 'Local Remote Override',
        'package' => 'capell-app/local-remote',
    ]), JSON_THROW_ON_ERROR));

    app()->bind(PluginPackagesFetcher::class, fn (): PluginPackagesFetcher => new class extends PluginPackagesFetcher
    {
        public function fetch(bool $force = false): Collection
        {
            /** @var array<int, array<string, mixed>> $packages */
            $packages = [
                [
                    'name' => 'capell-app/theme-remote',
                    'type' => 'theme',
                    'themeKey' => 'remote',
                    'description' => 'Remote cached theme.',
                ],
            ];

            return Collection::make($packages);
        }

        public function getCached(): Collection
        {
            return $this->fetch();
        }
    });

    $option = new ThemePackageCandidates(new PackageWorkflowPlanner)->optionDataForCatalogue()['remote'];

    expect($option->name)->toBe('Local Remote Override')
        ->and($option->packageName)->toBeNull()
        ->and(new ThemePackageCandidates(new PackageWorkflowPlanner)->packageNameForThemeKey('remote'))->toBeNull();
});

it('includes local app themes in installed and selected theme options', function (): void {
    writeLocalThemeManifest(json_encode(localAppThemeManifest(), JSON_THROW_ON_ERROR));

    $candidates = new ThemePackageCandidates(new PackageWorkflowPlanner);

    expect($candidates->optionsForInstalledPackages())->toHaveKey('local-test')
        ->and($candidates->optionsForSelection([]))->toHaveKey('local-test');
});

it('keeps registered package theme options ahead of local app theme metadata', function (): void {
    writeLocalThemeManifest(json_encode(localAppThemeManifest([
        'key' => 'corporate',
        'name' => 'Local Corporate',
    ]), JSON_THROW_ON_ERROR));

    CapellCore::registerPackage('capell-app/theme-corporate', type: PackageTypeEnum::Theme);
    CapellCore::getPackage('capell-app/theme-corporate')->themeKey = 'corporate';

    $option = new ThemePackageCandidates(new PackageWorkflowPlanner)->optionDataForCatalogue()['corporate'];

    expect($option->packageName)->toBe('capell-app/theme-corporate')
        ->and($option->name)->not->toBe('Local Corporate');
});

it('does not automatically register local app themes as public renderers', function (): void {
    writeLocalThemeManifest(json_encode(localAppThemeManifest(), JSON_THROW_ON_ERROR));

    resolve(LocalAppThemeDefinitionRepository::class)->all();

    expect(resolve(ThemeRegistry::class)->has('local-test'))->toBeFalse();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function localAppThemeManifest(array $overrides = []): array
{
    return [
        'key' => 'local-test',
        'name' => 'Local Test',
        'description' => 'A local app test theme.',
        'package' => 'capell-app/local-test',
        'previewImage' => '/images/local-test.jpg',
        'tags' => ['Local', 'Test'],
        'bestFit' => ['Product marketing'],
        'includedSections' => ['hero', 'features'],
        'presets' => [
            [
                'key' => 'launch',
                'name' => 'Launch',
                'description' => 'Launch preset.',
                'previewImage' => '/images/local-test-launch.jpg',
                'values' => [
                    'primaryColor' => '#123456',
                ],
            ],
        ],
        ...$overrides,
    ];
}

function writeLocalThemeManifest(string $contents): void
{
    $files = new Filesystem;
    $path = resource_path('views/capell/themes/capell-app/local-test');

    $files->ensureDirectoryExists($path);
    $files->put($path . '/theme.json', $contents);
}
