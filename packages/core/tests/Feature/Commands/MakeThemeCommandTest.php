<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Enums\VendorAssetEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Capell\Core\Testing\ExtensionTestHarness;
use Capell\Core\ThemeStudio\Theme\ThemeRegistry;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Console\Command\Command;

it('creates a project-local theme package scaffold', function (): void {
    $packagesDirectory = sys_get_temp_dir() . '/capell-make-theme-' . bin2hex(random_bytes(6));
    mkdir($packagesDirectory, 0755, true);

    artisanCommand('capell:make-theme', [
        'theme' => 'equidynamics',
        '--package' => 'app/equidynamics-theme',
        '--name' => 'Ben\'s "Launch" Theme',
        '--path' => $packagesDirectory,
        '--local' => true,
    ])
        ->expectsOutputToContain('Created Capell theme: equidynamics')
        ->assertExitCode(Command::SUCCESS);

    $themeDirectory = $packagesDirectory . '/equidynamics-theme';
    $providerPath = $themeDirectory . '/src/EquidynamicsThemeServiceProvider.php';
    $heroViewPath = $themeDirectory . '/resources/views/sections/hero.blade.php';
    $manifest = json_decode((string) file_get_contents($themeDirectory . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);
    $composer = json_decode((string) file_get_contents($themeDirectory . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    $lintOutput = [];
    $lintExitCode = 0;

    exec(sprintf('%s -l %s', escapeshellarg(PHP_BINARY), escapeshellarg($providerPath)), $lintOutput, $lintExitCode);

    expect($providerPath)->toBeFile()
        ->and($themeDirectory . '/resources/views/page.blade.php')->toBeFile()
        ->and($heroViewPath)->toBeFile()
        ->and($themeDirectory . '/resources/css/theme.css')->toBeFile()
        ->and($themeDirectory . '/resources/dist/preview.svg')->toBeFile()
        ->and($themeDirectory . '/tests/Feature/ThemeContractTest.php')->toBeFile()
        ->and($themeDirectory . '/tests/Pest.php')->toBeFile()
        ->and($themeDirectory . '/tests/TestCase.php')->toBeFile()
        ->and($themeDirectory . '/phpunit.xml.dist')->toBeFile()
        ->and($lintExitCode)->toBe(0)
        ->and($manifest['kind'])->toBe('theme')
        ->and($manifest['themeKey'])->toBe('equidynamics')
        ->and($manifest['displayName'])->toBe('Ben\'s "Launch" Theme')
        ->and($manifest['extends'])->toBe('default')
        ->and($manifest['visibility'])->toBe('support')
        ->and($manifest['capabilities'])->toBe(['frontend-rendering', 'tailwind-assets'])
        ->and($manifest['providers']['runtime'])->toBe(['App\\EquidynamicsTheme\\EquidynamicsThemeServiceProvider'])
        ->and($composer['extra']['laravel']['providers'])->toBe(['App\\EquidynamicsTheme\\EquidynamicsThemeServiceProvider'])
        ->and($composer['require'])->toHaveKey('capell-app/theme-foundation')
        ->and($composer['require-dev'])->toHaveKeys(['orchestra/testbench', 'pestphp/pest', 'pestphp/pest-plugin-laravel'])
        ->and($composer['scripts']['test'])->toBe('pest');

    $hero = Blade::render((string) file_get_contents($heroViewPath), [
        'heading' => 'Launch heading', 'eyebrow' => 'Public eyebrow', 'body' => '<script>private-content</script>',
    ]);
    expect($hero)->toContain('Launch heading', 'Public eyebrow', '&lt;script&gt;private-content&lt;/script&gt;')
        ->not->toContain('<script>', 'data-capell-edit', 'wire:', 'signed');
    $page = Blade::render((string) file_get_contents($themeDirectory . '/resources/views/page.blade.php'), [
        'content' => 'Hydrated page content',
    ]);
    expect($page)->toContain('Hydrated page content')->not->toContain('@frontendAsset');

    require $providerPath;
    $providerClass = $manifest['providers']['runtime'][0];
    throw_unless(is_string($providerClass) && is_subclass_of($providerClass, AbstractPackageServiceProvider::class), RuntimeException::class, 'Generated theme provider is not usable.');
    $provider = new $providerClass(app());
    CapellCore::registerPackage('app/equidynamics-theme', path: $themeDirectory);
    $provider->packageBooted();
    $definition = resolve(ThemeRegistry::class)->definition('equidynamics');
    expect($definition->name)->toBe('Ben\'s "Launch" Theme')
        ->and($definition->frontendBuildAssets()?->cssSource)->toBe('resources/css/theme.css')
        ->and($definition->frontendBuildAssets()?->condition)->toBe('theme-css:equidynamics');
    $imports = CapellCore::getVendorAssetsForType(VendorAssetEnum::TailwindImport);
    expect($imports->contains(fn ($asset): bool => $asset->packageName === 'app/equidynamics-theme' && $asset->condition === 'theme-css:equidynamics'))->toBeTrue();

    ExtensionTestHarness::forPath($themeDirectory)
        ->assertManifestValid()
        ->assertThemeManifest('equidynamics')
        ->assertThemeUsesSafeAssetUrls();

    expect(AuditExtensionContractsAction::run($themeDirectory))->toBe([]);
});

it('supports the colon-namespaced theme generator alias', function (): void {
    $packagesDirectory = sys_get_temp_dir() . '/capell-make-theme-alias-' . bin2hex(random_bytes(6));
    mkdir($packagesDirectory, 0755, true);

    artisanCommand('capell:make:theme', [
        'theme' => 'alias-theme',
        '--path' => $packagesDirectory,
    ])->assertExitCode(Command::SUCCESS);

    expect($packagesDirectory . '/alias-theme-theme/capell.json')->toBeFile();
});

it('rejects unsafe parent theme keys', function (): void {
    $packagesDirectory = sys_get_temp_dir() . '/capell-make-theme-bad-extends-' . bin2hex(random_bytes(6));
    mkdir($packagesDirectory, 0755, true);

    artisanCommand('capell:make-theme', [
        'theme' => 'equidynamics',
        '--path' => $packagesDirectory,
        '--extends' => 'Default Theme',
    ])
        ->expectsOutputToContain('The parent theme key must use lowercase letters, numbers, and hyphens.')
        ->assertExitCode(Command::FAILURE);
});

it('rejects unsafe theme keys and target paths', function (array $arguments, string $message): void {
    artisanCommand('capell:make-theme', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(Command::FAILURE);
})->with([
    'bad theme key' => [[
        'theme' => 'EquiDynamics',
        '--path' => sys_get_temp_dir(),
    ], 'lowercase letters'],
    'bad path' => [[
        'theme' => 'equidynamics',
        '--path' => '../outside',
    ], 'Missing or unsafe path'],
]);
