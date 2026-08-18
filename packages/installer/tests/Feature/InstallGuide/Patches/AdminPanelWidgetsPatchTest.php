<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelWidgetsPatch;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-admin-panel-widgets-patch-test-' . uniqid();

    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);

    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

function writeAdminPanelWidgetsPatchProvider(string $contents): string
{
    $path = base_path('app/Providers/Filament/AdminPanelProvider.php');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $path;
}

it('probe_returns_applicable_when_widgets_missing', function (): void {
    writeAdminPanelWidgetsPatchProvider(<<<'PHP'
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
PHP);

    expect((new AdminPanelWidgetsPatch)->probe())->toBe(PatchStatus::Applicable);
});

it('probe_returns_already_applied_when_widgets_present', function (): void {
    writeAdminPanelWidgetsPatchProvider(<<<'PHP'
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
            ->widgets([]);
    }
}
PHP);

    expect((new AdminPanelWidgetsPatch)->probe())->toBe(PatchStatus::AlreadyApplied);
});

it('probe_returns_customised_when_panel_has_multiple_statements', function (): void {
    writeAdminPanelWidgetsPatchProvider(<<<'PHP'
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
PHP);

    expect((new AdminPanelWidgetsPatch)->probe())->toBe(PatchStatus::Customised);
});

it('apply_appends_a_widgets_call_naming_the_three_dashboard_widgets', function (): void {
    $path = writeAdminPanelWidgetsPatchProvider(<<<'PHP'
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
PHP);

    (new AdminPanelWidgetsPatch)->apply();

    $contents = File::get($path);

    expect($contents)->toContain('->widgets([')
        ->and($contents)->toContain('ListPagesFilamentWidget::class')
        ->and($contents)->toContain('MyWorkQueueFilamentWidget::class')
        ->and($contents)->toContain('RecentlyPublishedFilamentWidget::class');
});

it('apply_throws_when_widgets_are_already_present', function (): void {
    writeAdminPanelWidgetsPatchProvider(<<<'PHP'
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
            ->widgets([]);
    }
}
PHP);

    expect(fn () => (new AdminPanelWidgetsPatch)->apply())->toThrow(RuntimeException::class);
});

it('probe_returns_unsupported_when_the_provider_file_is_missing', function (): void {
    expect((new AdminPanelWidgetsPatch)->probe())->toBe(PatchStatus::Unsupported);
});

it('patch_metadata_is_correct', function (): void {
    $patch = new AdminPanelWidgetsPatch;

    expect($patch->id())->toBe('admin-panel-widgets-patch');
    expect($patch->group())->toBe('providers');
    expect($patch->defaultEnabled())->toBeTrue();
    expect($patch->docUrl())->toBeNull();
    expect($patch->reason())->toBeNull();
});
