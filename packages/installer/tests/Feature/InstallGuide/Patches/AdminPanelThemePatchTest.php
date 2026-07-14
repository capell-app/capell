<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelThemePatch;
use Capell\Installer\Support\InstallGuide\PatchRegistry;
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

    $patch = new AdminPanelThemePatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    $updatedContent = (string) file_get_contents($providerPath);

    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and($updatedContent)->toContain("->viteTheme('resources/css/filament/admin/theme.css', 'build/filament')");
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

    $patch = new AdminPanelThemePatch;

    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and((string) file_get_contents($providerPath))->toContain("->viteTheme('resources/css/filament/admin/custom.css')");
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
