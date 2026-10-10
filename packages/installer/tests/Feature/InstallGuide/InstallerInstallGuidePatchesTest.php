<?php

declare(strict_types=1);

use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Admin\Filament\Pages\CapellDashboard;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Admin\Filament\Widgets\Dashboard\ListPagesFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\MyWorkQueueFilamentWidget;
use Capell\Admin\Filament\Widgets\Dashboard\RecentlyPublishedFilamentWidget;
use Capell\Core\Support\Activity\ActivityLogCompat;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Actions\InstallGuide\ApplyInstallGuidePatchesAction;
use Capell\Installer\Data\InstallGuide\ApplyPatchesInputData;
use Capell\Tests\Support\GeneratedPhpFixture;
use Capell\Tests\Support\JavascriptFixture;
use Dotenv\Dotenv;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
    writeInstallerInstallGuideFixture('bootstrap/app.php', installerInstallGuideBootstrapApplication());
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
        'runtime-role-bootstrap-patch',
        'env-queue-connection-patch',
        'env-settings-cache-patch',
        'filesystems-page-cache-disk-patch',
        'logging-capell-channel-patch',
    ];

    $result = ApplyInstallGuidePatchesAction::run(new ApplyPatchesInputData($patchIds));

    expect($result->results)->toHaveCount(count($patchIds))
        ->and($result->failed()->mapWithKeys(fn ($patchResult): array => [$patchResult->patchId => $patchResult->errorMessage])->all())->toBe([])
        ->and($result->succeeded())->toHaveCount(count($patchIds));

    $result->results->each(function ($patchResult): void {
        expect($patchResult->statusBefore)->toBe(PatchStatus::Applicable)
            ->and($patchResult->statusAfter)->toBe(PatchStatus::AlreadyApplied);
    });

    $provider = GeneratedPhpFixture::load(base_path('app/Providers/Filament/AdminPanelProvider.php'), PanelProvider::class, app());
    $panel = $provider->panel(Panel::make());
    expect($panel->hasPlugin(CapellAdminPlugin::make()->getId()))->toBeTrue()
        ->and(array_keys($panel->getColors()))->toContain(...array_keys(FilamentColorEnum::colors()))
        ->and($panel->getViteTheme())->toBe('resources/css/filament/admin/theme.css')
        ->and($panel->getPages())->toContain(CapellDashboard::class)
        ->and($panel->getWidgets())->toContain(ListPagesFilamentWidget::class, MyWorkQueueFilamentWidget::class, RecentlyPublishedFilamentWidget::class);

    $environment = Dotenv::parse(File::get(base_path('.env')));
    $filesystems = require base_path('config/filesystems.php');
    $logging = require base_path('config/logging.php');
    expect($environment)->toMatchArray(['QUEUE_CONNECTION' => 'database', 'SETTINGS_CACHE_ENABLED' => 'true'])
        ->and($filesystems['disks']['page_cache'])->toBe([
            'driver' => 'local', 'root' => public_path('page-cache'), 'throw' => false,
        ])
        ->and($logging['channels']['capell']['driver'])->toBe('single')
        ->and(JavascriptFixture::viteInputs(base_path('vite.config.js')))->toContain('resources/css/filament/admin/theme.css');

    Route::setRoutes(new RouteCollection);
    require base_path('routes/web.php');
    expect(fn () => Route::getRoutes()->match(Request::create('/')))
        ->toThrow(NotFoundHttpException::class);

    $user = GeneratedPhpFixture::load(base_path('app/Models/User.php'), Authenticatable::class);
    throw_unless($user instanceof FilamentUser, RuntimeException::class, 'The generated user must support Filament panels.');
    $originalMorphMap = Relation::morphMap();
    $originalAuthModel = config('auth.providers.users.model');
    Relation::morphMap(['installer-user' => $user::class]);
    config(['auth.providers.users.model' => $user::class]);
    try {
        expect($user->canAccessPanel($panel))->toBeFalse();
        $user->forceFill(['name' => 'Patched User', 'email' => 'patched-user@example.test', 'password' => 'private-password'])->save();
        $activityClass = ActivityLogCompat::activityModelClass();
        $activity = $activityClass::query()->where('log_name', 'user')->latest('id')->firstOrFail();
        $attributes = ActivityLogCompat::attributeValues($activity, 'attributes');
        expect($activity->getAttribute('event'))->toBe('created')
            ->and($attributes['name'] ?? null)->toBe('Patched User')
            ->and($attributes)->not->toHaveKey('password');
    } finally {
        Relation::morphMap($originalMorphMap, false);
        config(['auth.providers.users.model' => $originalAuthModel]);
    }

    // RuntimeRoleBootstrapPatchTest and ThemeSourcesPatchTest execute the generated
    // bootstrap and build all registered theme sources independently.

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
use Override;

class AdminPanelProvider extends PanelProvider
{
    #[Override]
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

function installerInstallGuideBootstrapApplication(): string
{
    return <<<'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
PHP;
}
