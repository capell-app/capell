<?php

declare(strict_types=1);

use Capell\Admin\Actions\AdminPanelIntegration\IntegrateCapellAdminPanelAction;
use Capell\Admin\Data\AdminPanelIntegration\AdminPanelSetupOptionsData;
use Capell\Admin\Support\AdminPanelIntegration\AdminPanelProviderEditor;
use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Tests\Support\GeneratedPhpFixture;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Theme;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = app()->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-panel-theme-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->temporaryBasePath);
    app()->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);
    File::deleteDirectory($this->temporaryBasePath);
});

it('themes only the adopted integration target with a different id', function (): void {
    $host = app_path('Providers/Filament/FirstHostPanelProvider.php');
    $selected = app_path('Providers/Filament/SelectedPanelProvider.php');
    File::ensureDirectoryExists(dirname($host));
    $source = '<?php namespace App\\Providers\\Filament; class %s extends \\Filament\\PanelProvider { #[\\Override] public function panel(\\Filament\\Panel $panel): \\Filament\\Panel { return $panel->id("%s")->colors(["primary" => "amber"]); } }';
    File::put($host, sprintf($source, 'FirstHostPanelProvider', 'host'));
    File::put($selected, sprintf($source, 'SelectedPanelProvider', 'workspace'));
    $hostBytes = File::get($host);
    $selectedBytes = File::get($selected);

    InstallFilamentPanelAction::run(new NullProgressReporter);
    expect(File::get($host))->toBe($hostBytes)
        ->and(File::get($selected))->toBe($selectedBytes);
    $options = new AdminPanelSetupOptionsData(panelPath: 'workspace', addColors: false, addWidgets: false, addNavigation: false, createBackup: false);
    $result = IntegrateCapellAdminPanelAction::run($options);
    expect($result->hasFailures())->toBeFalse()
        ->and($result->panelPath)->toBe(realpath($selected));
    $configured = File::get($selected);
    IntegrateCapellAdminPanelAction::run($options);
    InstallFilamentPanelAction::run(new NullProgressReporter);
    $panel = GeneratedPhpFixture::load($selected, PanelProvider::class, app())->panel(Panel::make());
    expect($panel->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and($panel->getColors()['primary'])->toBe('amber')
        ->and(File::get($selected))->toBe($configured)
        ->and(File::get($host))->toBe($hostBytes)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeTrue();
});

it('preserves the adopted providers custom theme across repeated integration', function (string $method): void {
    $path = app_path('Providers/Filament/CustomPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php namespace App\\Providers\\Filament; class CustomPanelProvider extends \\Filament\\PanelProvider { #[\\Override] public function panel(\\Filament\\Panel $panel): \\Filament\\Panel { return $panel->id("workspace")->' . $method . '("custom.css"); } }');
    $options = new AdminPanelSetupOptionsData(panelPath: 'workspace', addColors: false, addWidgets: false, addNavigation: false, createBackup: false);

    expect(IntegrateCapellAdminPanelAction::run($options)->hasFailures())->toBeFalse();
    $configured = File::get($path);
    expect(IntegrateCapellAdminPanelAction::run($options)->hasFailures())->toBeFalse();
    $panel = GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make());
    $theme = $panel->getTheme();
    expect($method === 'viteTheme' ? $panel->getViteTheme() : ($theme instanceof Theme ? $theme->getPath() : null))->toBe('custom.css')
        ->and(File::get($path))->toBe($configured)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
})->with(['viteTheme', 'theme']);

it('does not theme or change the selected provider in integration preview', function (): void {
    $path = app_path('Providers/Filament/PreviewPanelProvider.php');
    $source = '<?php namespace App\\Providers\\Filament; class PreviewPanelProvider { public function panel($panel) { return $panel->id("workspace"); } }';
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $source);
    $result = IntegrateCapellAdminPanelAction::run(new AdminPanelSetupOptionsData(panelPath: 'workspace', preview: true));

    expect($result->hasFailures())->toBeFalse()
        ->and(File::get($path))->toBe($source)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});

it('creates a missing stylesheet for an adopted default theme without rewriting the provider again', function (): void {
    $path = app_path('Providers/Filament/AdoptedDefaultPanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php namespace App\\Providers\\Filament; class AdoptedDefaultPanelProvider { public function panel($panel) { return $panel->id("workspace")->viteTheme("resources/css/filament/admin/theme.css"); } }');
    $options = new AdminPanelSetupOptionsData(panelPath: 'workspace', addColors: false, addWidgets: false, addNavigation: false, createBackup: false);

    expect(IntegrateCapellAdminPanelAction::run($options)->hasFailures())->toBeFalse()
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
    $integrated = File::get($path);
    File::delete(resource_path('css/filament/admin/theme.css'));
    expect(IntegrateCapellAdminPanelAction::run($options)->hasFailures())->toBeFalse()
        ->and(File::get($path))->toBe($integrated)
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
});

it('preserves scaffold ownership across adoption editor saves before theming resumes', function (): void {
    $path = app_path('Providers/Filament/WorkspacePanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php namespace App\\Providers\\Filament; class WorkspacePanelProvider { public function panel($panel) { return $panel->id("workspace"); } }');
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    $editor = new AdminPanelProviderEditor($path);
    $editor->addColors();
    $editor->addPlugin([]);
    $editor->save();

    expect(InstallRunState::scaffoldedPanelProviders())->toContain('app/Providers/Filament/WorkspacePanelProvider.php');
    InstallFilamentPanelAction::run(new NullProgressReporter);
    expect(resource_path('css/filament/admin/theme.css'))->toBeFile();
    $configured = File::get($path);
    IntegrateCapellAdminPanelAction::run(new AdminPanelSetupOptionsData(createBackup: false));
    $integrated = File::get($path);
    expect($integrated)->not->toBe($configured);
    InstallFilamentPanelAction::run(new NullProgressReporter);
    expect(File::get($path))->toBe($integrated);
});

it('refuses to overwrite a host replacement made after the adoption editor read the provider', function (): void {
    $path = app_path('Providers/Filament/WorkspacePanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php class WorkspacePanelProvider { public function panel($panel) { return $panel->id("workspace"); } }');
    InstallRunState::recordScaffoldedPanelProviders([$path]);
    $editor = new AdminPanelProviderEditor($path);
    $editor->addColors();

    $replacement = '<?php class WorkspacePanelProvider { public function panel($panel) { return $panel->id("host"); } }';
    File::put($path, $replacement);

    expect(fn () => $editor->save())->toThrow(RuntimeException::class);
    InstallFilamentPanelAction::run(new NullProgressReporter);
    expect(File::get($path))->toBe($replacement)
        ->and(InstallRunState::scaffoldedPanelProviders())->toBe([])
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});

it('honours a declined installer theme prompt when integration runs during an install', function (): void {
    $path = app_path('Providers/Filament/WorkspacePanelProvider.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<?php namespace App\\Providers\\Filament; class WorkspacePanelProvider extends \\Filament\\PanelProvider { #[\\Override] public function panel(\\Filament\\Panel $panel): \\Filament\\Panel { return $panel->id("workspace"); } }');
    InstallRunState::rememberPanelThemeDecision($path, accepted: false);
    $options = new AdminPanelSetupOptionsData(panelPath: 'workspace', addColors: false, addWidgets: false, addNavigation: false, createBackup: false);

    expect(IntegrateCapellAdminPanelAction::run($options)->hasFailures())->toBeFalse()
        ->and(GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make())->getViteTheme())->toBeNull()
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
});
