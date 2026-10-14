<?php

declare(strict_types=1);

use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelThemePatch;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
use Capell\Tests\Support\GeneratedPanelProvider;
use Capell\Tests\Support\GeneratedPhpFixture;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->testDirectory = sys_get_temp_dir() . '/capell-admin-theme-patch-test-' . uniqid();

    File::ensureDirectoryExists($this->testDirectory);
    $this->app->setBasePath($this->testDirectory);
});

afterEach(function (): void {
    if (is_dir($this->testDirectory)) {
        File::deleteDirectory($this->testDirectory);
    }
});

it('adds the default Filament Vite theme when no theme is configured', function (): void {
    $providerPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $providerDirectory = dirname($providerPath);

    if (! is_dir($providerDirectory)) {
        mkdir($providerDirectory, 0755, true);
    }

    file_put_contents($providerPath, stockAdminPanelThemeProvider());

    InstallRunState::recordScaffoldedPanelProviders([$providerPath]);
    $patch = new AdminPanelThemePatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and(GeneratedPanelProvider::load($providerPath)->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
});

it('does not overwrite an existing custom Filament Vite theme', function (): void {
    $providerPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $providerDirectory = dirname($providerPath);

    if (! is_dir($providerDirectory)) {
        mkdir($providerDirectory, 0755, true);
    }

    file_put_contents($providerPath, str_replace(
        '->login();',
        "->viteTheme('resources/css/filament/admin/custom.css')\n            ->login();",
        stockAdminPanelThemeProvider(),
    ));

    InstallRunState::recordScaffoldedPanelProviders([$providerPath]);
    $patch = new AdminPanelThemePatch;

    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and(GeneratedPanelProvider::load($providerPath)->getViteTheme())->toBe('resources/css/filament/admin/custom.css');
});

it('treats a manually registered Filament asset theme as already configured', function (): void {
    $providerPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $providerDirectory = dirname($providerPath);

    if (! is_dir($providerDirectory)) {
        mkdir($providerDirectory, 0755, true);
    }

    file_put_contents($providerPath, str_replace(
        '->login();',
        "->theme(asset('css/filament/admin/theme.css'))\n            ->login();",
        stockAdminPanelThemeProvider(),
    ));

    InstallRunState::recordScaffoldedPanelProviders([$providerPath]);
    expect((new AdminPanelThemePatch)->probe())->toBe(PatchStatus::AlreadyApplied);
});

it('is available to the web install guide patch registry', function (): void {
    expect(resolve(PatchRegistry::class)->get('admin-panel-theme-patch'))
        ->toBeInstanceOf(AdminPanelThemePatch::class);
});

function stockAdminPanelThemeProvider(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    #[\Override]
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login();
    }
}
PHP;
}

it('does not patch an unowned stock-shaped provider at the conventional path', function (): void {
    $path = app_path('Providers/Filament/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    $source = stockAdminPanelThemeProvider();
    File::put($path, $source);
    $patch = new AdminPanelThemePatch;

    expect($patch->probe())->toBe(PatchStatus::Unsupported);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class);
    expect(File::get($path))->toBe($source)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});

it('patches only the owned provider when it has an unconventional path', function (): void {
    $host = app_path('Providers/Filament/AdminPanelProvider.php');
    $owned = app_path('Providers/Filament/WorkspacePanelProvider.php');
    File::ensureDirectoryExists(dirname($host));
    File::put($host, stockAdminPanelThemeProvider());
    File::put($owned, str_replace(['AdminPanelProvider', "->id('admin')"], ['WorkspacePanelProvider', "->id('workspace')"], stockAdminPanelThemeProvider()));
    $hostBytes = File::get($host);
    InstallRunState::recordScaffoldedPanelProviders([$owned]);
    $patch = new AdminPanelThemePatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);
    $patch->apply();
    expect(GeneratedPhpFixture::load($owned, PanelProvider::class, app())->panel(Panel::make())->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and(File::get($host))->toBe($hostBytes)
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
});

it('repairs the default stylesheet for an owned provider already referencing it', function (): void {
    $path = app_path('Providers/Filament/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    $source = str_replace('->login();', "->viteTheme('resources/css/filament/admin/theme.css')->login();", stockAdminPanelThemeProvider());
    File::put($path, $source);
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    $patch = new AdminPanelThemePatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);
    $patch->apply();
    expect(File::get($path))->toBe($source)
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile()
        ->and($patch->probe())->toBe(PatchStatus::AlreadyApplied);
});

it('themes an explicitly adopted provider without touching the conventional host panel', function (): void {
    $host = app_path('Providers/Filament/AdminPanelProvider.php');
    $selected = app_path('Providers/Filament/SelectedPanelProvider.php');
    File::ensureDirectoryExists(dirname($host));
    File::put($host, stockAdminPanelThemeProvider());
    File::put($selected, str_replace('AdminPanelProvider', 'SelectedPanelProvider', stockAdminPanelThemeProvider()));
    $hostBytes = File::get($host);
    $patch = new AdminPanelThemePatch(providerPath: $selected);

    expect($patch->probe())->toBe(PatchStatus::Applicable);
    $patch->apply();
    expect(GeneratedPhpFixture::load($selected, PanelProvider::class, app())->panel(Panel::make())->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and(File::get($host))->toBe($hostBytes)
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
});

it('contributes automatic theme patches only for authorised targets', function (bool $owned): void {
    $path = app_path('Providers/Filament/WorkspacePanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, str_replace('AdminPanelProvider', 'WorkspacePanelProvider', stockAdminPanelThemeProvider()));

    if ($owned) {
        InstallRunState::recordScaffoldedPanelProviders([$path]);
    }

    $patches = resolve(InstallPatchRegistry::class)->patchesFor(new InstallPatchContext(['capell-app/admin'], true));
    $themePatches = collect($patches)->pluck('patch')->filter(fn ($patch): bool => $patch instanceof AdminPanelThemePatch);

    expect($themePatches->count())->toBe($owned ? 1 : 0);

    if ($owned) {
        expect($themePatches->first()?->probe())->toBe(PatchStatus::Applicable);
    }
})->with([false, true]);
