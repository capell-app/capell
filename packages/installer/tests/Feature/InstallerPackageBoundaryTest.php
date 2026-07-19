<?php

declare(strict_types=1);

use Capell\Installer\Actions\GetActiveInstallAction;
use Capell\Installer\Bridges\InstallerAdminBridge;
use Capell\Installer\Providers\InstallerAdminServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;

use function Pest\Laravel\get;

use Symfony\Component\Process\Process;

it('keeps the installer package installable without the admin package', function (): void {
    $composerContents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');

    $composerJson = json_decode(
        $composerContents !== false ? $composerContents : '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composerJson['require'] ?? [])
        ->toHaveKey('capell-app/core')
        ->not->toHaveKey('capell-app/admin')
        ->not->toHaveKey('filament/filament')
        ->not->toHaveKey('filament/support');
});

it('declares admin as an optional supported package in the Capell manifest', function (): void {
    $manifestContents = file_get_contents(dirname(__DIR__, 2) . '/capell.json');

    $manifest = json_decode(
        $manifestContents !== false ? $manifestContents : '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($manifest['dependencies']['requires'] ?? [])
        ->toBe(['capell-app/core'])
        ->and($manifest['dependencies']['supports'] ?? [])
        ->toContain('capell-app/admin')
        ->and($manifest['providers']['install'] ?? [])
        ->toBe([InstallerServiceProvider::class])
        ->and($manifest['providers']['admin'] ?? [])
        ->toBe([InstallerAdminServiceProvider::class]);
});

it('keeps the general installer provider free of admin and Filament dependencies', function (): void {
    $providerContents = file_get_contents(
        dirname(__DIR__, 2) . '/src/Providers/InstallerServiceProvider.php',
    );

    expect($providerContents)->toBeString()
        ->not->toContain('Capell\\Admin\\')
        ->not->toContain('Filament\\')
        ->not->toContain(InstallerAdminBridge::class);
});

it('discovers the general installer provider without autoloading admin-only classes', function (): void {
    $projectRoot = dirname(__DIR__, 4);
    $autoloadPath = $projectRoot . '/vendor/autoload.php';

    $script = sprintf(
        <<<'PHP'
        require %s;

        spl_autoload_register(
            static function (string $class): void {
                if (str_starts_with($class, 'Capell\\Admin\\') || str_starts_with($class, 'Filament\\')) {
                    throw new LogicException("Unexpected optional admin autoload: {$class}");
                }
            },
            true,
            true,
        );

        if (! class_exists(%s)) {
            throw new LogicException('The Installer service provider was not discoverable.');
        }

        echo 'installer-provider-discovered';
        PHP,
        var_export($autoloadPath, true),
        var_export(InstallerServiceProvider::class, true),
    );

    $process = new Process([PHP_BINARY, '-r', $script], $projectRoot);
    $process->mustRun();

    expect($process->getOutput())->toBe('installer-provider-discovered');
});

it('uses standalone web installer routes for active install progress', function (): void {
    Cache::put('capell.install.lock', [
        'installId' => 'external-installer-route-test',
        'queued' => true,
    ]);
    Cache::put('capell.install.external-installer-route-test.status', 'running');

    $activeInstall = GetActiveInstallAction::run();

    expect($activeInstall)->not->toBeNull()
        ->and($activeInstall->progressUrl)->toBe(route('capell-installer.progress', [
            'installId' => 'external-installer-route-test',
        ]))
        ->and($activeInstall->progressUrl)->not->toContain('/admin/');
});

it('clears terminal active install locks before reporting installer progress', function (): void {
    Cache::put('capell.install.lock', [
        'installId' => 'finished-installer-route-test',
        'queued' => true,
    ]);
    Cache::put('capell.install.finished-installer-route-test.status', 'complete');

    expect(GetActiveInstallAction::run())->toBeNull()
        ->and(Cache::has('capell.install.lock'))->toBeFalse();
});

it('fails closed when active install cache lookups throw', function (): void {
    Cache::shouldReceive('get')
        ->with('capell.install.lock')
        ->andThrow(new RuntimeException('cache unavailable'));

    expect(GetActiveInstallAction::run())->toBeNull();
});

it('owns the installer web, filament, view, and language surfaces', function (): void {
    $projectRoot = dirname(__DIR__, 4);

    expect($projectRoot . '/packages/installer/routes/web.php')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/views/layouts/installer.blade.php')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/css/installer.css')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/js/install.js')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/js/install/support.js')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/js/install/wizard.js')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/js/install/packages.js')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/js/install/form-options.js')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/js/install/progress.js')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/js/install/csrf.js')->toBeFile()
        ->and($projectRoot . '/packages/installer/resources/js/install/runner.js')->toBeFile()
        ->and($projectRoot . '/packages/installer/src/Filament/Pages/InstallGuidePage.php')->toBeFile()
        ->and($projectRoot . '/packages/core/routes/install.php')->not->toBeFile()
        ->and($projectRoot . '/packages/core/resources/installer-lang')->not->toBeDirectory()
        ->and($projectRoot . '/packages/core/resources/views/install.blade.php')->not->toBeFile()
        ->and($projectRoot . '/packages/admin/src/Filament/Pages/InstallGuidePage.php')->not->toBeFile()
        ->and($projectRoot . '/packages/admin/src/Filament/Widgets/CapellNotInstalledFilamentWidget.php')->not->toBeFile();
});

it('renders installer pages through the shared installer layout', function (): void {
    $projectRoot = dirname(__DIR__, 4);
    $installView = file_get_contents($projectRoot . '/packages/installer/resources/views/install.blade.php');
    $progressView = file_get_contents($projectRoot . '/packages/installer/resources/views/progress.blade.php');

    expect($installView)->toStartWith("@extends('capell-installer::layouts.installer')")
        ->and($progressView)->toStartWith("@extends('capell-installer::layouts.installer')")
        ->and($installView)->not->toContain('<style>')
        ->and($progressView)->not->toContain('<style>');
});

it('leaves request execution limits to hosting configuration', function (): void {
    $projectRoot = dirname(__DIR__, 4);
    $sourceRoot = $projectRoot . '/packages/installer/src';
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));
    $offendingFiles = [];

    foreach ($sourceFiles as $sourceFile) {
        if (! $sourceFile instanceof SplFileInfo) {
            continue;
        }

        if (! $sourceFile->isFile()) {
            continue;
        }

        if ($sourceFile->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($sourceFile->getPathname());

        if ($contents !== false && str_contains($contents, 'set_time_limit(')) {
            $offendingFiles[] = str_replace($projectRoot . '/', '', $sourceFile->getPathname());
        }
    }

    expect($offendingFiles)->toBe([]);
});

it('renders the web installer without the admin logo view namespace', function (): void {
    View::replaceNamespace('capell-admin', []);

    get(route('capell-installer.show'))
        ->assertOk()
        ->assertSee(__('capell-installer::installer.page_title'));
});
