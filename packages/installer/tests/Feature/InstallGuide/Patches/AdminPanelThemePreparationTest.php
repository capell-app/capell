<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\InstallFilamentPanelAction;
use Capell\Core\Actions\Install\PrepareInstallApplicationAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Install\InstallPatchConfirmation;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Install\InstallRunState;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Install\PanelProviderTargetResolver;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelThemePatch;
use Capell\Tests\Support\GeneratedPhpFixture;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->originalBasePath = app()->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-theme-preparation-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->temporaryBasePath);
    app()->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    app()->setBasePath($this->originalBasePath);
    File::deleteDirectory($this->temporaryBasePath);
});

it('themes only the explicitly selected admin panel during CLI preparation', function (string $selection): void {
    $host = themePreparationProvider('AdminPanelProvider', 'admin');
    $selected = themePreparationProvider('WorkspacePanelProvider', 'workspace');
    $hostBytes = File::get($host);

    prepareThemeInstall(adminPanel: $selection, interactive: false);

    expect(File::get($host))->toBe($hostBytes)
        ->and(themePreparationViteTheme($selected))->toBe('resources/css/filament/admin/theme.css')
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
})->with([
    'panel id' => 'workspace',
    'provider class' => 'App\\Providers\\Filament\\WorkspacePanelProvider',
    'provider path' => 'app/Providers/Filament/WorkspacePanelProvider.php',
]);

it('reviews the theme patch for the explicitly selected admin panel', function (): void {
    themePreparationProvider('AdminPanelProvider', 'admin');
    $selected = themePreparationProvider('WorkspacePanelProvider', 'workspace');
    File::put($selected, str_replace("->id('workspace')", "->id('workspace')->viteTheme('custom.css')", File::get($selected)));

    $patches = resolve(InstallPatchRegistry::class)->patchesFor(new InstallPatchContext(['capell-app/admin'], true, adminPanel: 'workspace'));
    $theme = collect($patches)->first(fn ($registered): bool => $registered->patch instanceof AdminPanelThemePatch);

    expect($theme?->patch->probe())->toBe(PatchStatus::AlreadyApplied);
});

it('honours a declined theme prompt on resume and default adoption until the user opts in', function (bool $scaffolded): void {
    $path = themePreparationProvider('AdminPanelProvider', 'admin');

    if ($scaffolded) {
        InstallRunState::recordScaffoldedPanelProviders([$path]);
    }

    $original = File::get($path);
    $skipped = prepareThemeInstall(interactive: true, accept: false);

    expect($skipped)->toContain('→ Skipped Filament Vite theme registration.');

    // Resume the install step and the non-interactive preparation that follows it.
    InstallFilamentPanelAction::run(new NullProgressReporter);
    $resumed = prepareThemeInstall(interactive: false);
    $adopted = new AdminPanelThemePatch(adoptDefault: true);

    expect(File::get($path))->toBe($original)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse()
        ->and($resumed)->toContain('→ Skipped Filament Vite theme registration.')
        ->and($adopted->probe())->toBe(PatchStatus::Applicable);

    prepareThemeInstall(interactive: true, accept: true);

    expect(themePreparationViteTheme($path))->toBe('resources/css/filament/admin/theme.css')
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();

    $configured = File::get($path);
    InstallFilamentPanelAction::run(new NullProgressReporter);
    prepareThemeInstall(interactive: false);

    expect(File::get($path))->toBe($configured);
})->with([
    'scaffolded panel' => true,
    'adopted default panel' => false,
]);

it('keeps a declined theme on one panel from blocking the theme for another selected panel', function (): void {
    $declined = themePreparationProvider('AdminPanelProvider', 'admin');
    $selected = themePreparationProvider('WorkspacePanelProvider', 'workspace');
    prepareThemeInstall(interactive: true, accept: false);
    $declinedBytes = File::get($declined);

    prepareThemeInstall(adminPanel: 'workspace', interactive: false);

    expect(File::get($declined))->toBe($declinedBytes)
        ->and(themePreparationViteTheme($selected))->toBe('resources/css/filament/admin/theme.css');
});

it('treats an explicit theme patch apply as opting back in', function (): void {
    $path = themePreparationProvider('AdminPanelProvider', 'admin');
    prepareThemeInstall(interactive: true, accept: false);

    new AdminPanelThemePatch(providerPath: 'app/Providers/Filament/AdminPanelProvider.php')->apply();

    expect(themePreparationViteTheme($path))->toBe('resources/css/filament/admin/theme.css')
        ->and(resource_path('css/filament/admin/theme.css'))->toBeFile();
});

it('refuses an ambiguous admin panel selection instead of theming the first match', function (string $selection): void {
    $first = themePreparationProvider('AdminPanelProvider', 'admin');
    $tenant = app_path('Providers/Filament/Tenant/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($tenant));
    File::put($tenant, str_replace('namespace App\\Providers\\Filament;', 'namespace App\\Providers\\Filament\\Tenant;', File::get($first)));
    $firstBytes = File::get($first);
    $tenantBytes = File::get($tenant);

    prepareThemeInstall(adminPanel: $selection, interactive: false);

    expect(resolve(PanelProviderTargetResolver::class)->resolve($selection))->toBe([])
        ->and(File::get($first))->toBe($firstBytes)
        ->and(File::get($tenant))->toBe($tenantBytes)
        ->and(File::exists(resource_path('css/filament/admin/theme.css')))->toBeFalse();
})->with([
    'shared panel id' => 'admin',
    'shared class name' => 'AdminPanelProvider',
]);

it('still targets an ambiguous name by its unique class or path', function (string $selection): void {
    $first = themePreparationProvider('AdminPanelProvider', 'admin');
    $tenant = app_path('Providers/Filament/Tenant/AdminPanelProvider.php');
    File::ensureDirectoryExists(dirname($tenant));
    File::put($tenant, str_replace('namespace App\\Providers\\Filament;', 'namespace App\\Providers\\Filament\\Tenant;', File::get($first)));
    $firstBytes = File::get($first);

    prepareThemeInstall(adminPanel: $selection, interactive: false);

    expect(File::get($first))->toBe($firstBytes)
        ->and(themePreparationViteTheme((string) realpath($tenant)))->toBe('resources/css/filament/admin/theme.css');
})->with([
    'provider class' => 'App\\Providers\\Filament\\Tenant\\AdminPanelProvider',
    'provider path' => 'app/Providers/Filament/Tenant/AdminPanelProvider.php',
]);

function themePreparationProvider(string $class, string $id): string
{
    $path = app_path('Providers/Filament/' . $class . '.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\\Providers\\Filament;

use Filament\\Panel;
use Filament\\PanelProvider;

class {$class} extends PanelProvider
{
    #[\\Override]
    public function panel(Panel \$panel): Panel
    {
        return \$panel
            ->id('{$id}')
            ->path('{$id}')
            ->login();
    }
}
PHP);

    return (string) realpath($path);
}

/** @return string|array<string>|null */
function themePreparationViteTheme(string $path): string|array|null
{
    return GeneratedPhpFixture::load($path, PanelProvider::class, app())->panel(Panel::make())->getViteTheme();
}

/** @return list<string> */
function prepareThemeInstall(?string $adminPanel = null, bool $interactive = false, bool $accept = true): array
{
    $reporter = new class implements ProgressReporter
    {
        /** @var list<string> */
        public array $messages = [];

        #[Override]
        public function step(string $message): void
        {
            $this->messages[] = $message;
        }

        #[Override]
        public function report(string $message): void
        {
            $this->messages[] = $message;
        }

        #[Override]
        public function error(string $message): void
        {
            $this->messages[] = $message;
        }
    };

    PrepareInstallApplicationAction::run(
        inputData: new InstallInputData(
            siteUrl: 'https://example.test',
            packages: ['capell-app/admin'],
            languages: ['en'],
            demoContent: false,
            cachesToClear: [],
            generateSitemap: false,
            generateStaticSite: false,
            adminPanel: $adminPanel,
        ),
        hasFilamentAdminPanelProvider: true,
        interactive: $interactive,
        useFreshDemoDefaults: false,
        reporter: $reporter,
        confirmPatch: static fn (InstallPatchConfirmation $confirmation): bool => $accept,
        recordManualInstallChange: static function (string $message): void {},
    );

    return $reporter->messages;
}
