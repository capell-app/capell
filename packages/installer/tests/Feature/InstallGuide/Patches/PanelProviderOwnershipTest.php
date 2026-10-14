<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Patching\Patch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelColorsPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelDashboardPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelNavigationPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelPluginPatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelProviderPatcher;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelThemePatch;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelWidgetsPatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = app()->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-provider-ownership-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->temporaryBasePath);
    app()->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);
    File::deleteDirectory($this->temporaryBasePath);
});

it('keeps the scaffolded target through every provider patch and completed retries', function (Patch $patch): void {
    $host = app_path('Providers/Filament/AdminPanelProvider.php');
    $target = app_path('Providers/Filament/WorkspacePanelProvider.php');
    $source = '<?php namespace App\\Providers\\Filament; use Filament\\Pages\\Dashboard; class %s { public function panel($panel) { return $panel->id("%s")->pages([Dashboard::class]); } }';
    File::ensureDirectoryExists(dirname($target));
    File::put($host, sprintf($source, 'AdminPanelProvider', 'host'));
    File::put($target, sprintf($source, 'WorkspacePanelProvider', 'workspace'));
    $hostBytes = File::get($host);
    InstallRunState::recordScaffoldedPanelProviders([$target]);

    expect($patch->probe())->toBe(PatchStatus::Applicable);
    $patch->apply();
    expect(InstallRunState::scaffoldedPanelProviders())->toContain('app/Providers/Filament/WorkspacePanelProvider.php');
    (new AdminPanelThemePatch)->apply();
    $configured = File::get($target);
    InstallFilamentPanelAction::run(new NullProgressReporter);
    expect(InstallRunState::scaffoldedPanelProviders())->toBe([]);
    File::delete(resource_path('css/filament/admin/theme.css'));

    InstallFilamentPanelAction::run(new NullProgressReporter);

    expect(resource_path('css/filament/admin/theme.css'))->toBeFile()
        ->and(File::get($target))->toBe($configured)
        ->and(File::get($host))->toBe($hostBytes)
        ->and(new AdminPanelThemePatch(adoptDefault: true)->probe())->toBe(PatchStatus::AlreadyApplied);
})->with([
    new AdminPanelColorsPatch,
    new AdminPanelDashboardPatch,
    new AdminPanelNavigationPatch,
    new AdminPanelPluginPatch,
    new AdminPanelWidgetsPatch,
]);

it('does not adopt a replacement at a completed scaffold path on later integration passes', function (): void {
    $path = app_path('Providers/Filament/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php class AdminPanelProvider { public function panel($panel) { return $panel->id("admin"); } }');
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    InstallFilamentPanelAction::run(new NullProgressReporter);
    $replacement = '<?php class AdminPanelProvider { public function panel($panel) { return $panel->id("host"); } }';
    File::put($path, $replacement);
    File::delete(resource_path('css/filament/admin/theme.css'));

    InstallFilamentPanelAction::run(new NullProgressReporter);
    $patch = new AdminPanelThemePatch(adoptDefault: true);
    expect($patch->probe())->toBe(PatchStatus::Unsupported);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class);
    expect(File::get($path))->toBe($replacement)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});

it('resolves an explicitly selected relative path within the host application', function (): void {
    $relative = 'app/Providers/Filament/WorkspacePanelProvider.php';
    $target = base_path($relative);
    $otherDirectory = base_path('other-working-directory');
    $other = $otherDirectory . '/' . $relative;
    $source = '<?php class WorkspacePanelProvider { public function panel($panel) { return $panel->id("workspace"); } }';
    File::ensureDirectoryExists(dirname($target));
    File::ensureDirectoryExists(dirname($other));
    File::put($target, $source);
    File::put($other, $source);
    $workingDirectory = getcwd();
    throw_unless(is_string($workingDirectory), RuntimeException::class, 'Unable to read working directory.');

    try {
        chdir($otherDirectory);
        new AdminPanelColorsPatch(new AdminPanelProviderPatcher(providerPath: $relative))->apply();
        expect(File::get($other))->toBe($source)
            ->and(File::get($target))->not->toBe($source);
    } finally {
        chdir($workingDirectory);
    }
});

it('requires explicit adoption when an older path-only record cannot verify ownership', function (): void {
    $relative = 'app/Providers/Filament/AdminPanelProvider.php';
    $path = base_path($relative);
    $source = '<?php class AdminPanelProvider { public function panel($panel) { return $panel->id("host"); } }';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $source);
    Cache::build(['driver' => 'file', 'path' => base_path('storage/framework/cache/data')])
        ->forever('capell.install.state', ['scaffolded_panel_providers' => [$relative]]);

    $automatic = new AdminPanelThemePatch(adoptDefault: true);
    expect($automatic->probe())->toBe(PatchStatus::Unsupported)
        ->and(new AdminPanelColorsPatch()->probe())->toBe(PatchStatus::Unsupported);
    expect(fn () => $automatic->apply())->toThrow(RuntimeException::class);
    expect(File::get($path))->toBe($source)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();

    new AdminPanelThemePatch(providerPath: $relative)->apply();
    expect(resource_path('css/filament/admin/theme.css'))->toBeFile()
        ->and(new AdminPanelThemePatch()->probe())->toBe(PatchStatus::AlreadyApplied);
});
