<?php

declare(strict_types=1);

use Capell\Core\Facades\CapellCore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    CapellCore::clearPackages();
});

afterEach(function (): void {
    CapellCore::clearPackages();
});

it('installs one named extension and forwards declared params only', function (): void {
    $calls = [];

    Artisan::command('test:extension-install {--url=} {--limit=} {--ignored=}', function () use (&$calls): int {
        $calls[] = [
            'url' => $this->option('url'),
            'limit' => $this->option('limit'),
            'ignored' => $this->option('ignored'),
        ];

        return 0;
    });

    CapellCore::registerPackage('vendor/example-extension');
    CapellCore::getPackage('vendor/example-extension')->installCommand = 'test:extension-install';
    CapellCore::getPackage('vendor/example-extension')->installParams = ['url', 'limit'];

    artisanCommand('capell:extension-install', [
        'extension' => 'vendor/example-extension',
        '--url' => 'https://capell.test',
        '--param' => [
            'limit=10',
            'ignored=not-forwarded',
        ],
    ])->assertSuccessful();

    expect($calls)->toBe([
        [
            'url' => 'https://capell.test',
            'limit' => '10',
            'ignored' => null,
        ],
    ]);
});

it('expands named extension requirements before installing the selected extension', function (): void {
    $calls = [];

    Artisan::command('test:forms-core-install', function () use (&$calls): int {
        $calls[] = 'forms-core';

        return 0;
    });

    Artisan::command('test:form-builder-install', function () use (&$calls): int {
        $calls[] = 'form-builder';

        return 0;
    });

    CapellCore::registerPackage('vendor/forms-core');
    CapellCore::getPackage('vendor/forms-core')->installCommand = 'test:forms-core-install';

    CapellCore::registerPackage('vendor/form-builder');
    CapellCore::getPackage('vendor/form-builder')->installCommand = 'test:form-builder-install';
    CapellCore::getPackage('vendor/form-builder')->requirements = ['vendor/forms-core'];

    artisanCommand('capell:extension-install', [
        'extension' => 'vendor/form-builder',
    ])->assertSuccessful();

    expect($calls)->toBe(['forms-core', 'form-builder']);
});

it('refreshes cached filament menu components after installing an extension', function (): void {
    $cachedPanelPath = base_path('bootstrap/cache/filament/panels/admin.php');

    File::ensureDirectoryExists(dirname($cachedPanelPath));
    File::put($cachedPanelPath, '<?php return ["pages" => ["Vendor\\\\Example\\\\AdminPage"]];');

    CapellCore::registerPackage('vendor/example-extension');

    artisanCommand('capell:extension-install', [
        'extension' => 'vendor/example-extension',
    ])->assertSuccessful();

    expect(File::exists($cachedPanelPath))->toBeFalse();
});

it('installs all not already installed extensions only when all is requested', function (): void {
    $calls = [];

    Artisan::command('test:first-extension-install', function () use (&$calls): int {
        $calls[] = 'first';

        return 0;
    });

    Artisan::command('test:second-extension-install', function () use (&$calls): int {
        $calls[] = 'second';

        return 0;
    });

    Artisan::command('test:installed-extension-install', function () use (&$calls): int {
        $calls[] = 'installed';

        return 0;
    });

    CapellCore::registerPackage('vendor/first-extension');
    CapellCore::getPackage('vendor/first-extension')->installCommand = 'test:first-extension-install';

    CapellCore::registerPackage('vendor/second-extension');
    CapellCore::getPackage('vendor/second-extension')->installCommand = 'test:second-extension-install';
    CapellCore::getPackage('vendor/second-extension')->requirements = ['vendor/first-extension'];

    CapellCore::registerPackage('vendor/installed-extension');
    CapellCore::getPackage('vendor/installed-extension')->installCommand = 'test:installed-extension-install';
    CapellCore::forcePackageInstalled('vendor/installed-extension');

    artisanCommand('capell:extension-install', [
        '--all' => true,
    ])->assertSuccessful();

    expect($calls)->toBe(['first', 'second']);
});

it('rejects default non-interactive runs without an extension or all flag', function (): void {
    CapellCore::registerPackage('vendor/example-extension');

    $exitCode = Artisan::call('capell:extension-install', [
        '--no-interaction' => true,
    ]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('Pass an extension package name or use --all.');
});

it('can dry run selected installed extensions when explicitly included', function (): void {
    CapellCore::registerPackage('vendor/installed-extension');
    CapellCore::getPackage('vendor/installed-extension')->installParams = ['flag'];
    CapellCore::forcePackageInstalled('vendor/installed-extension');

    artisanCommand('capell:extension-install', [
        'extension' => 'vendor/installed-extension',
        '--include-installed' => true,
        '--dry-run' => true,
        '--param' => ['flag'],
    ])
        ->expectsOutput('Would install vendor/installed-extension with {"--flag":true}')
        ->assertSuccessful();
});

it('does not re-run installed requirements when reinstalling a selected installed extension', function (): void {
    $calls = [];

    Artisan::command('test:installed-core-install', function () use (&$calls): int {
        $calls[] = 'installed-core';

        return 0;
    });

    Artisan::command('test:installed-extension-install', function () use (&$calls): int {
        $calls[] = 'installed-extension';

        return 0;
    });

    CapellCore::registerPackage('vendor/installed-core');
    CapellCore::getPackage('vendor/installed-core')->installCommand = 'test:installed-core-install';
    CapellCore::forcePackageInstalled('vendor/installed-core');

    CapellCore::registerPackage('vendor/installed-extension');
    CapellCore::getPackage('vendor/installed-extension')->installCommand = 'test:installed-extension-install';
    CapellCore::getPackage('vendor/installed-extension')->requirements = ['vendor/installed-core'];
    CapellCore::forcePackageInstalled('vendor/installed-extension');

    artisanCommand('capell:extension-install', [
        'extension' => 'vendor/installed-extension',
        '--include-installed' => true,
    ])->assertSuccessful();

    expect($calls)->toBe(['installed-extension']);
});
