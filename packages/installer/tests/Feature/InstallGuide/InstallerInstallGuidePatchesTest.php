<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Actions\InstallGuide\ApplyInstallGuidePatchesAction;
use Capell\Installer\Data\InstallGuide\ApplyPatchesInputData;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-installer-install-guide-' . uniqid();

    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);

    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

it('applies the installer install guide patches to a stock Laravel and Filament skeleton', function (): void {
    writeInstallerInstallGuideFixture('.env', "APP_NAME=Capell\nQUEUE_CONNECTION=sync\n");
    writeInstallerInstallGuideFixture('app/Models/User.php', installerInstallGuideUserModel());
    writeInstallerInstallGuideFixture('app/Providers/Filament/AdminPanelProvider.php', installerInstallGuideAdminPanelProvider());
    writeInstallerInstallGuideFixture('config/filesystems.php', installerInstallGuideFilesystemsConfig());
    writeInstallerInstallGuideFixture('config/logging.php', installerInstallGuideLoggingConfig());
    writeInstallerInstallGuideFixture('resources/css/filament/admin/theme.css', "@import '../../../../vendor/filament/filament/resources/css/theme.css';\n");
    writeInstallerInstallGuideFixture('routes/web.php', installerInstallGuideRoutes());
    writeInstallerInstallGuideFixture('vite.config.js', "export default { input: ['resources/css/app.css', 'resources/js/app.js'] };\n");

    $patchIds = [
        'user-model-patch',
        'admin-panel-colors-patch',
        'admin-panel-dashboard-patch',
        'admin-panel-navigation-patch',
        'admin-panel-plugin-patch',
        'admin-panel-theme-patch',
        'admin-panel-widgets-patch',
        'theme-sources-patch',
        'vite-theme-input-patch',
        'remove-welcome-route-patch',
        'env-queue-connection-patch',
        'env-settings-cache-patch',
        'filesystems-page-cache-disk-patch',
        'logging-capell-channel-patch',
    ];

    $result = ApplyInstallGuidePatchesAction::run(new ApplyPatchesInputData($patchIds));

    expect($result->results)->toHaveCount(count($patchIds))
        ->and($result->failed())->toBeEmpty()
        ->and($result->succeeded())->toHaveCount(count($patchIds));

    $result->results->each(function ($patchResult): void {
        expect($patchResult->statusBefore)->toBe(PatchStatus::Applicable)
            ->and($patchResult->statusAfter)->toBe(PatchStatus::AlreadyApplied);
    });

    $adminPanelProvider = File::get(base_path('app/Providers/Filament/AdminPanelProvider.php'));
    expect($adminPanelProvider)->toContain('CapellAdminPlugin::make()')
        ->and($adminPanelProvider)->toContain('FilamentColorEnum::colors()')
        ->and($adminPanelProvider)->toContain('CapellDashboard::class')
        ->and($adminPanelProvider)->toContain('CapellAdmin::getNavigationItems()')
        ->and($adminPanelProvider)->toContain('CapellAdmin::getNavigationGroups()')
        ->and($adminPanelProvider)->toContain('ListPagesFilamentWidget::class')
        ->and($adminPanelProvider)->toContain("viteTheme('resources/css/filament/admin/theme.css')");

    expect(File::get(base_path('.env')))->toContain('QUEUE_CONNECTION=database')
        ->and(File::get(base_path('.env')))->toContain('SETTINGS_CACHE_ENABLED=true')
        ->and(File::get(base_path('config/filesystems.php')))->toContain("'page_cache'")
        ->and(File::get(base_path('config/logging.php')))->toContain("'capell'")
        ->and(File::get(base_path('resources/css/filament/admin/theme.css')))->toContain('vendor/capell-app/installer/resources/views/**/*.blade.php')
        ->and(File::get(base_path('vite.config.js')))->toContain("'resources/css/filament/admin/theme.css'")
        ->and(File::get(base_path('routes/web.php')))->not->toContain("Route::get('/', function ()");

    $userModel = File::get(base_path('app/Models/User.php'));
    expect($userModel)->toContain('implements FilamentUser')
        ->and($userModel)->toContain('HasPanelShield')
        ->and($userModel)->toContain('getActivitylogOptions');
});

function writeInstallerInstallGuideFixture(string $relativePath, string $contents): void
{
    $path = base_path($relativePath);

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);
}

function installerInstallGuideAdminPanelProvider(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Pages\Dashboard;
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
            ->pages([
                Dashboard::class,
            ]);
    }
}
PHP;
}

function installerInstallGuideUserModel(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;
}
PHP;
}

function installerInstallGuideFilesystemsConfig(): string
{
    return <<<'PHP'
<?php

return [
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],
    ],
];
PHP;
}

function installerInstallGuideLoggingConfig(): string
{
    return <<<'PHP'
<?php

return [
    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single'],
        ],
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
        ],
    ],
];
PHP;
}

function installerInstallGuideRoutes(): string
{
    return <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});
PHP;
}
