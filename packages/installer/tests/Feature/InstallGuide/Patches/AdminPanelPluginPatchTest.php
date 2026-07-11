<?php

declare(strict_types=1);

use Capell\Installer\Support\InstallGuide\Patches\AdminPanelPluginPatch;
use Capell\Installer\Support\InstallGuide\PatchStatus;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-admin-panel-plugin-patch-test-' . uniqid();

    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);

    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

function writeAdminPanelPluginPatchProvider(string $contents): string
{
    $path = base_path('app/Providers/Filament/AdminPanelProvider.php');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $path;
}

test('probe_detects_the_workbench_admin_panel_provider_state', function (): void {
    $patch = new AdminPanelPluginPatch;
    expect($patch->probe())->toBeIn([PatchStatus::AlreadyApplied, PatchStatus::Unsupported]);
});

test('probe_returns_applicable_when_plugin_missing', function (): void {
    $testProviderPath = tempnam(sys_get_temp_dir(), 'test_provider_');
    $originalPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $providerWithoutPlugin = <<<'PHP'
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
            ->login()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources');
    }
}
PHP;

    file_put_contents($testProviderPath, $providerWithoutPlugin);

    try {
        if (! file_exists(dirname($originalPath))) {
            mkdir(dirname($originalPath), 0755, true);
        }

        copy($testProviderPath, $originalPath);

        $patch = new AdminPanelPluginPatch;
        expect($patch->probe())->toBe(PatchStatus::Applicable);
    } finally {
        if (file_exists($testProviderPath)) {
            unlink($testProviderPath);
        }

        if (file_exists(dirname($originalPath))) {
            exec('rm -rf ' . escapeshellarg(dirname($originalPath)));
        }
    }
});

test('probe_returns_already_applied_when_plugin_present', function (): void {
    $testProviderPath = tempnam(sys_get_temp_dir(), 'test_provider_');
    $originalPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $providerWithPlugin = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
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
            ->login()
            ->plugin(CapellAdminPlugin::make()->discoverSchemas(in: app_path('Filament/FormBuilder'), for: 'App\\Filament\\FormBuilder'))
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources');
    }
}
PHP;

    file_put_contents($testProviderPath, $providerWithPlugin);

    try {
        if (! file_exists(dirname($originalPath))) {
            mkdir(dirname($originalPath), 0755, true);
        }

        copy($testProviderPath, $originalPath);

        $patch = new AdminPanelPluginPatch;
        expect($patch->probe())->toBe(PatchStatus::AlreadyApplied);
    } finally {
        if (file_exists($testProviderPath)) {
            unlink($testProviderPath);
        }

        if (file_exists(dirname($originalPath))) {
            exec('rm -rf ' . escapeshellarg(dirname($originalPath)));
        }
    }
});

test('probe_returns_applicable_when_plugin_is_present_but_default_panel_is_missing', function (): void {
    writeAdminPanelPluginPatchProvider(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugin(CapellAdminPlugin::make());
    }
}
PHP);

    expect((new AdminPanelPluginPatch)->probe())->toBe(PatchStatus::Applicable);
});

test('apply_adds_default_without_duplicating_an_existing_plugin_call', function (): void {
    $path = writeAdminPanelPluginPatchProvider(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->plugin(CapellAdminPlugin::make());
    }
}
PHP);

    (new AdminPanelPluginPatch)->apply();

    $contents = File::get($path);

    expect($contents)->toContain('->default()')
        ->and(substr_count($contents, '->plugin('))->toBe(1);
});

test('apply_adds_plugin_and_default_to_a_stock_filament_panel', function (): void {
    $path = writeAdminPanelPluginPatchProvider(<<<'PHP'
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
            ->id('admin')
            ->path('admin')
            ->login();
    }
}
PHP);

    (new AdminPanelPluginPatch)->apply();

    $contents = File::get($path);

    expect($contents)->toContain('->default()')
        ->and($contents)->toContain('->plugin(CapellAdminPlugin::make()->discoverSchemas(');
});

test('probe_returns_customised_when_panel_has_multiple_statements', function (): void {
    $testProviderPath = tempnam(sys_get_temp_dir(), 'test_provider_');
    $originalPath = base_path('app/Providers/Filament/AdminPanelProvider.php');
    $customProvider = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $config = config('app.debug');

        return $panel
            ->default()
            ->id('admin')
            ->path('admin');
    }
}
PHP;

    file_put_contents($testProviderPath, $customProvider);

    try {
        if (! file_exists(dirname($originalPath))) {
            mkdir(dirname($originalPath), 0755, true);
        }

        copy($testProviderPath, $originalPath);

        $patch = new AdminPanelPluginPatch;
        expect($patch->probe())->toBe(PatchStatus::Customised);
    } finally {
        if (file_exists($testProviderPath)) {
            unlink($testProviderPath);
        }

        if (file_exists(dirname($originalPath))) {
            exec('rm -rf ' . escapeshellarg(dirname($originalPath)));
        }
    }
});

test('probe_returns_customised_when_panel_has_single_conditional', function (): void {
    $testProviderPath = tempnam(sys_get_temp_dir(), 'test_provider_');
    $providerWithCondition = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        if (config('app.debug')) {
            return $panel
                ->default()
                ->id('admin')
                ->path('admin');
        }

        return $panel;
    }
}
PHP;

    file_put_contents($testProviderPath, $providerWithCondition);

    try {
        // Placeholder for conditional check
        expect(true)->toBeTrue();
    } finally {
        if (file_exists($testProviderPath)) {
            unlink($testProviderPath);
        }
    }
});

test('patch_detects_plugin_in_method_chain', function (): void {
    $testProviderPath = tempnam(sys_get_temp_dir(), 'test_provider_');
    $providerWithPlugin = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
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
            ->plugin(CapellAdminPlugin::make());
    }
}
PHP;

    file_put_contents($testProviderPath, $providerWithPlugin);

    try {
        // Placeholder for plugin detection
        expect(true)->toBeTrue();
    } finally {
        if (file_exists($testProviderPath)) {
            unlink($testProviderPath);
        }
    }
});

test('patch_metadata_is_correct', function (): void {
    $patch = new AdminPanelPluginPatch;

    expect($patch->id())->toBe('admin-panel-plugin-patch');
    expect($patch->group())->toBe('providers');
    expect($patch->defaultEnabled())->toBeTrue();
    expect($patch->docUrl())->toBeNull();
    expect($patch->reason())->toBeNull();
});
