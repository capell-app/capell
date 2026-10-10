<?php

declare(strict_types=1);

use Capell\Installer\Actions\GetActiveInstallAction;
use Capell\Installer\Providers\InstallerAdminServiceProvider;
use Capell\Installer\Providers\InstallerServiceProvider;
use Capell\Installer\Support\InstallerSessionRepository;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

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
    $run = resolve(InstallerSessionRepository::class)->run('external-installer-route-test');
    $run->startQueued();
    $run->markRunning();

    $activeInstall = GetActiveInstallAction::run();

    expect($activeInstall)->not->toBeNull()
        ->and($activeInstall->progressUrl)->toBe(route('capell-installer.progress', [
            'installId' => 'external-installer-route-test',
        ]))
        ->and($activeInstall->progressUrl)->not->toContain('/admin/');
});

it('clears terminal active install locks before reporting installer progress', function (): void {
    $run = resolve(InstallerSessionRepository::class)->run('finished-installer-route-test');
    $run->startQueued();
    $run->markComplete();

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

it('renders installer pages with the shared document chrome', function (): void {
    $installId = (string) Str::uuid();
    $this->withSession(['capell.install.' . $installId . '.access' => true]);
    $run = resolve(InstallerSessionRepository::class)->run($installId);
    $run->startQueued();
    $run->markRunning();
    foreach ([route('capell-installer.show'), route('capell-installer.progress', ['installId' => $installId])] as $url) {
        $response = get($url)->assertOk();
        $dom = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
        expect($dom->getElementsByTagName('title')->length)->toBe(1)
            ->and($dom->getElementsByTagName('body')->length)->toBe(1)
            ->and($response->getContent())->toContain('capell-installer');
    }
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

        // Host time limits must not be widened by installer request handlers.
        if ($contents !== false && preg_match('/\bset_time_limit\s*\(/', $contents) === 1) {
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
