<?php

declare(strict_types=1);

use Capell\Core\Testing\ExtensionTestHarness;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Command\Command;

if (! function_exists('makeExtensionWorkbenchDirectory')) {
    function makeExtensionWorkbenchDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/capell-make-extension-' . bin2hex(random_bytes(6));

        mkdir($directory, 0755, true);

        return $directory;
    }
}

it('creates a minimal package scaffold without modifying root composer dependencies', function (): void {
    $packagesDirectory = makeExtensionWorkbenchDirectory();
    $rootComposer = file_get_contents(base_path('composer.json'));

    artisanCommand('capell:make-extension', [
        'package' => 'vendor/example',
        '--name' => 'Example',
        '--profile' => 'minimal',
        '--premium' => true,
        '--path' => $packagesDirectory,
    ])
        ->expectsOutputToContain('Created Capell package: vendor/example')
        ->assertExitCode(Command::SUCCESS);

    $extensionDirectory = $packagesDirectory . '/example';

    expect($extensionDirectory)->toBeDirectory()
        ->and($extensionDirectory . '/capell.json')->toBeFile()
        ->and($extensionDirectory . '/src/Providers/PackageServiceProvider.php')->toBeFile()
        ->and($extensionDirectory . '/resources/lang/en/package.php')->toBeFile()
        ->and($extensionDirectory . '/README.md')->toBeFile()
        ->and($extensionDirectory . '/composer.json')->toBeFile()
        ->and($extensionDirectory . '/phpunit.xml.dist')->toBeFile()
        ->and($extensionDirectory . '/tests/Pest.php')->toBeFile()
        ->and($extensionDirectory . '/tests/TestCase.php')->toBeFile()
        ->and($extensionDirectory . '/src/Providers/AdminServiceProvider.php')->not->toBeFile()
        ->and(file_get_contents(base_path('composer.json')))->toBe($rootComposer);

    $manifest = json_decode((string) file_get_contents($extensionDirectory . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);
    $composer = json_decode((string) file_get_contents($extensionDirectory . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['manifest-version'])->toBe(3)
        ->and($manifest['name'])->toBe('vendor/example')
        ->and($manifest['displayName'])->toBe('Example')
        ->and($manifest['product']['tier'])->toBe('premium')
        ->and($manifest['surfaces'])->toBe(['shared'])
        ->and($manifest['providers']['runtime'])->toBe(['Vendor\\Example\\Providers\\PackageServiceProvider'])
        ->and($manifest['performance']['cacheSafety']['cacheable'])->toBeFalse()
        ->and($composer['require'])->toHaveKeys(['capell-app/core', 'spatie/laravel-package-tools'])
        ->and($composer['require-dev'])->toHaveKeys(['orchestra/testbench', 'pestphp/pest', 'pestphp/pest-plugin-laravel'])
        ->and($composer['scripts']['test'])->toBe('pest')
        ->and($composer['extra']['laravel']['providers'])->toBe(['Vendor\\Example\\Providers\\PackageServiceProvider']);

    ExtensionTestHarness::forPath($extensionDirectory)
        ->assertManifestValid()
        ->assertNoUnsafePublicCache();
});

it('supports the colon-namespaced extension generator alias', function (): void {
    $packagesDirectory = makeExtensionWorkbenchDirectory();

    artisanCommand('capell:make:extension', [
        'package' => 'vendor/alias-example',
        '--profile' => 'minimal',
        '--path' => $packagesDirectory,
        '--no-interaction' => true,
    ])->assertExitCode(Command::SUCCESS);

    expect($packagesDirectory . '/alias-example/capell.json')->toBeFile();
});

it('creates a full package scaffold with live safe examples', function (): void {
    $packagesDirectory = makeExtensionWorkbenchDirectory();

    artisanCommand('capell:make-extension', [
        'package' => 'vendor/example-tools',
        '--name' => 'Example Tools',
        '--profile' => 'full',
        '--path' => $packagesDirectory,
    ])
        ->expectsOutputToContain('Created Capell package: vendor/example-tools')
        ->assertExitCode(Command::SUCCESS);

    $extensionDirectory = $packagesDirectory . '/example-tools';
    $manifest = json_decode((string) file_get_contents($extensionDirectory . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);
    $composer = json_decode((string) file_get_contents($extensionDirectory . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($extensionDirectory . '/src/Providers/MetadataServiceProvider.php')->toBeFile()
        ->and($extensionDirectory . '/src/Providers/InstallServiceProvider.php')->toBeFile()
        ->and($extensionDirectory . '/src/Providers/PackageServiceProvider.php')->toBeFile()
        ->and($extensionDirectory . '/src/Providers/AdminServiceProvider.php')->toBeFile()
        ->and($extensionDirectory . '/src/Console/Commands/ExamplePackageCommand.php')->toBeFile()
        ->and($extensionDirectory . '/src/ExampleWidgetContribution.php')->toBeFile()
        ->and($extensionDirectory . '/src/Filament/ExampleWidget.php')->toBeFile()
        ->and($extensionDirectory . '/src/Data/ExampleWidgetInputData.php')->toBeFile()
        ->and($extensionDirectory . '/src/Data/ExampleWidgetRenderData.php')->toBeFile()
        ->and($extensionDirectory . '/src/Settings/PackageSettings.php')->toBeFile()
        ->and($extensionDirectory . '/resources/views/widget.blade.php')->toBeFile()
        ->and($extensionDirectory . '/resources/dist/widget.css')->toBeFile()
        ->and($extensionDirectory . '/resources/dist/widget.js')->toBeFile()
        ->and($extensionDirectory . '/tests/Feature/ProviderDiscoveryTest.php')->toBeFile()
        ->and($extensionDirectory . '/tests/Pest.php')->toBeFile()
        ->and($extensionDirectory . '/tests/TestCase.php')->toBeFile()
        ->and($manifest['manifest-version'])->toBe(3)
        ->and($manifest['surfaces'])->toBe(['admin', 'frontend', 'console', 'shared'])
        ->and($manifest['providers'])->toHaveKeys(['metadata', 'install', 'runtime', 'admin', 'frontend'])
        ->and($manifest['providers']['metadata'])->toBe(['Vendor\\ExampleTools\\Providers\\MetadataServiceProvider'])
        ->and($manifest['providers']['install'])->toBe(['Vendor\\ExampleTools\\Providers\\InstallServiceProvider'])
        ->and($manifest['providers']['runtime'])->toBe(['Vendor\\ExampleTools\\Providers\\PackageServiceProvider'])
        ->and($manifest['providers']['admin'])->toBe(['Vendor\\ExampleTools\\Providers\\AdminServiceProvider'])
        ->and($manifest['providers']['frontend'])->toBe([])
        ->and($manifest['dependencies']['requires'])->toBe(['capell-app/core', 'capell-app/admin', 'capell-app/frontend', 'capell-app/layout-builder'])
        ->and($manifest['contributes'])->toBe([[
            'type' => 'content-widget',
            'key' => 'vendor.example-tools',
            'class' => 'Vendor\\ExampleTools\\ExampleWidgetContribution',
        ]])
        ->and($manifest['performance']['cacheSafety']['cacheable'])->toBeFalse()
        ->and($composer['require'])->toHaveKeys(['capell-app/core', 'capell-app/admin', 'capell-app/frontend', 'capell-app/layout-builder', 'spatie/laravel-data', 'spatie/laravel-package-tools'])
        ->and($composer['extra']['laravel']['providers'])->toBe(['Vendor\\ExampleTools\\Providers\\PackageServiceProvider']);

});

it('prompts for missing interactive values', function (): void {
    $packagesDirectory = makeExtensionWorkbenchDirectory();

    artisanCommand('capell:make-extension')
        ->expectsQuestion('Composer package name, for example vendor/example', 'vendor/prompted')
        ->expectsChoice('Scaffold profile', 'minimal', ['minimal', 'full'])
        ->expectsQuestion('Target directory', $packagesDirectory)
        ->expectsQuestion('Display name', 'Prompted')
        ->assertExitCode(Command::SUCCESS);

    expect($packagesDirectory . '/prompted/capell.json')->toBeFile();
});

it('requires profile and path in non-interactive mode', function (array $arguments, string $message): void {
    artisanCommand('capell:make-extension', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(Command::FAILURE);
})->with([
    'missing profile' => [[
        'package' => 'vendor/example',
        '--path' => '/tmp/capell-example',
        '--no-interaction' => true,
    ], 'Missing required profile'],
    'missing path' => [[
        'package' => 'vendor/example',
        '--profile' => 'minimal',
        '--no-interaction' => true,
    ], 'Missing or unsafe path'],
    'invalid profile' => [[
        'package' => 'vendor/example',
        '--profile' => 'expanded',
        '--path' => '/tmp/capell-example',
        '--no-interaction' => true,
    ], 'Invalid profile'],
]);

it('rejects invalid package names and unsafe targets', function (string $packageName, string $message): void {
    artisanCommand('capell:make-extension', [
        'package' => $packageName,
        '--profile' => 'minimal',
        '--path' => makeExtensionWorkbenchDirectory(),
    ])
        ->expectsOutputToContain($message)
        ->assertExitCode(Command::FAILURE);
})->with([
    'path traversal' => ['vendor/../bad', 'valid Composer package name'],
    'reserved platform namespace' => ['capell-app/core', 'reserved for Capell platform packages'],
    'invalid composer name' => ['Vendor/Example', 'valid Composer package name'],
]);

it('rejects unsafe path options', function (): void {
    artisanCommand('capell:make-extension', [
        'package' => 'vendor/example',
        '--profile' => 'minimal',
        '--path' => '../outside',
        '--no-interaction' => true,
    ])
        ->expectsOutputToContain('Missing or unsafe path')
        ->assertExitCode(Command::FAILURE);
});

it('rejects existing file target paths cleanly', function (): void {
    $packagesDirectory = makeExtensionWorkbenchDirectory();
    $targetPath = $packagesDirectory . '/example';

    file_put_contents($targetPath, 'not a directory');

    artisanCommand('capell:make-extension', [
        'package' => 'vendor/example',
        '--profile' => 'minimal',
        '--path' => $packagesDirectory,
    ])
        ->expectsOutputToContain('already exists and is not a directory')
        ->assertExitCode(Command::FAILURE);
});

it('rejects existing non-empty target directories', function (): void {
    $packagesDirectory = makeExtensionWorkbenchDirectory();
    $targetDirectory = $packagesDirectory . '/example';

    File::ensureDirectoryExists($targetDirectory);
    file_put_contents($targetDirectory . '/README.md', 'already here');

    artisanCommand('capell:make-extension', [
        'package' => 'vendor/example',
        '--profile' => 'minimal',
        '--path' => $packagesDirectory,
    ])
        ->expectsOutputToContain('already exists and is not empty')
        ->assertExitCode(Command::FAILURE);
});

it('generates full scaffold safety assertions and a render-data-only public view', function (): void {
    $packagesDirectory = makeExtensionWorkbenchDirectory();

    artisanCommand('capell:make-extension', [
        'package' => 'vendor/safety-demo',
        '--profile' => 'full',
        '--path' => $packagesDirectory,
        '--no-interaction' => true,
    ])->assertExitCode(Command::SUCCESS);

    $extensionDirectory = $packagesDirectory . '/safety-demo';
    $manifestTest = (string) file_get_contents($extensionDirectory . '/tests/Feature/ManifestTest.php');
    $widgetView = (string) file_get_contents($extensionDirectory . '/resources/views/widget.blade.php');

    expect($manifestTest)
        ->toContain('assertManifestValid()')
        ->toContain('assertNoUnsafePublicCache()')
        ->and($widgetView)
        ->toContain('ExampleWidgetRenderData $widget')
        ->toContain('$widget->heading')
        ->toContain('$widget->body')
        ->not->toContain('$record')
        ->not->toContain('$model');
});
