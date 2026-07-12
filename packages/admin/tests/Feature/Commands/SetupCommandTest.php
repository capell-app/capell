<?php

declare(strict_types=1);

use Capell\Admin\Actions\AdminPanelIntegration\DiscoverFilamentPanelsAction;
use Capell\Admin\Actions\AdminPanelIntegration\IntegrateCapellAdminPanelAction;
use Capell\Admin\Console\Commands\SetupCommand;
use Capell\Admin\Enums\CapellPermission;
use Capell\Admin\Tests\Support\AdminPanelProviderFixtures;
use Capell\Core\Enums\PackageTypeEnum;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Support\Manifest\CapellManifestData;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

afterEach(function (): void {
    cleanupSetupCommandPanelProvider();
    app()->offsetUnset(DiscoverFilamentPanelsAction::class);
    app()->offsetUnset(IntegrateCapellAdminPanelAction::class);
});

it('runs admin setup command successfully', function (): void {
    $user = User::factory()->createOne(['email' => 'admin@example.com']);

    artisanCommand('capell:admin-setup', [
        '--url' => 'https://example.test',
        '--user' => $user->email,
        '--languages' => 'en,fr',
        '--sites' => 'Main Site,Sub Site',
        '--assets' => [
            'resources/css/app.css',
            'resources/js/app.js',
        ],
        '--skip-shield' => true,
        '--skip-panel-integration' => true,
    ])
        ->assertExitCode(0)
        ->expectsOutput('Admin setup: resolving install inputs')
        ->expectsOutput('Admin setup: publishing settings migrations')
        ->expectsOutput('Admin setup: running settings migrations')
        ->expectsOutput('Admin setup: creating core content types')
        ->expectsOutput('Admin setup: creating languages')
        ->expectsOutput('Admin setup: creating theme')
        ->expectsOutput('Admin setup: creating layouts')
        ->expectsOutput('Admin setup: creating sites and default pages')
        ->expectsOutput('Setting up site: Main Site')
        ->expectsOutput('Setting up site: Sub Site')
        ->expectsOutput('Admin setup: syncing dashboard Filament widget settings')
        ->expectsOutput('Admin setup complete.');
});

it('uses default frontend assets when no assets option is passed', function (): void {
    $user = User::factory()->createOne(['email' => 'default-assets@example.com']);

    File::ensureDirectoryExists(resource_path('css'));
    File::put(resource_path('css/app.css'), 'body { color: inherit; }');

    try {
        $exitCode = Artisan::call('capell:admin-setup', [
            '--url' => 'https://example.test',
            '--user' => $user->email,
            '--languages' => 'en',
            '--sites' => 'Main Site',
            '--skip-shield' => true,
            '--skip-panel-integration' => true,
            '--no-interaction' => true,
        ]);

        $theme = Theme::query()->where('key', 'default')->first();

        expect($exitCode)->toBe(0)
            ->and($theme?->meta['assets'] ?? null)->toBe([
                'resources/css/app.css',
            ]);
    } finally {
        File::delete(resource_path('css/app.css'));
    }
});

it('uses selected theme for the initial site during setup', function (): void {
    $user = User::factory()->createOne(['email' => 'theme@example.com']);
    registerInstalledSetupTheme('capell-app/theme-corporate', 'corporate');

    artisanCommand('capell:admin-setup', [
        '--url' => 'https://example.test',
        '--user' => $user->email,
        '--languages' => 'en',
        '--sites' => 'Main Site',
        '--theme' => 'corporate',
        '--assets' => [
            'resources/css/app.css',
            'resources/js/app.js',
        ],
        '--skip-shield' => true,
        '--skip-panel-integration' => true,
    ])->assertExitCode(0);

    $theme = Theme::query()->where('key', 'corporate')->first();
    $site = Site::query()->where('name', 'Main Site')->first();

    expect($theme)->not->toBeNull()
        ->and($site?->theme_id)->toBe($theme?->id);
});

it('normalises the legacy foundation setup theme to the built in default theme', function (): void {
    $user = User::factory()->createOne(['email' => 'foundation-alias@example.com']);

    artisanCommand('capell:admin-setup', [
        '--url' => 'https://example.test',
        '--user' => $user->email,
        '--languages' => 'en',
        '--sites' => 'Main Site',
        '--theme' => 'foundation',
        '--assets' => [
            'resources/css/app.css',
            'resources/js/app.js',
        ],
        '--skip-shield' => true,
        '--skip-panel-integration' => true,
    ])->assertExitCode(0);

    $theme = Theme::query()->where('key', 'default')->first();
    $site = Site::query()->where('name', 'Main Site')->first();

    expect($theme)->not->toBeNull()
        ->and($site?->theme_id)->toBe($theme?->id)
        ->and(Theme::query()->where('key', 'foundation')->exists())->toBeFalse();
});

it('rejects unknown selected themes during setup', function (): void {
    $user = User::factory()->createOne(['email' => 'unknown-theme@example.com']);

    artisanCommand('capell:admin-setup', [
        '--url' => 'https://example.test',
        '--user' => $user->email,
        '--languages' => 'en',
        '--sites' => 'Main Site',
        '--theme' => 'missing-theme',
        '--assets' => [
            'resources/css/app.css',
            'resources/js/app.js',
        ],
        '--skip-shield' => true,
        '--skip-panel-integration' => true,
    ])
        ->expectsOutputToContain('Unknown theme [missing-theme].')
        ->assertExitCode(1);
});

it('creates Capell admin permissions during setup', function (): void {
    $user = User::factory()->createOne(['email' => 'permissions@example.com']);

    artisanCommand('capell:admin-setup', [
        '--url' => 'https://example.test',
        '--user' => $user->email,
        '--languages' => 'en',
        '--sites' => 'Main Site',
        '--assets' => [
            'resources/css/app.css',
            'resources/js/app.js',
        ],
        '--skip-panel-integration' => true,
    ])->assertExitCode(0);

    expect(Permission::query()->pluck('name')->all())->toContain(
        'ViewAny:Page',
        'ViewAny:Site',
        'ViewAny:Layout',
        'ViewAny:PageUrl',
        CapellPermission::ManageSitePermissions->name(),
        CapellPermission::ManagePageRestrictions->name(),
    )
        ->and(Role::findByName('admin')->hasPermissionTo(CapellPermission::ManageSitePermissions->name(), 'web'))->toBeTrue();
});

it('skips permission sync when setup follows install', function (): void {
    Permission::findOrCreate('custom.client.permission');
    Role::findOrCreate('admin')->givePermissionTo('custom.client.permission');

    $user = User::factory()->createOne(['email' => 'existing-role@example.com']);

    artisanCommand('capell:admin-setup', [
        '--url' => 'https://example.test',
        '--user' => $user->email,
        '--languages' => 'en',
        '--sites' => 'Main Site',
        '--assets' => [
            'resources/css/app.css',
            'resources/js/app.js',
        ],
        '--skip-permission-sync' => true,
        '--skip-panel-integration' => true,
    ])
        ->doesntExpectOutput('Setting up filament shield authentication')
        ->assertExitCode(0);

    $adminRole = Role::findByName('admin');

    expect($adminRole->hasPermissionTo('custom.client.permission', 'web'))->toBeTrue()
        ->and(Permission::query()->where('name', CapellPermission::ManageSitePermissions->name())->exists())->toBeFalse();
});

it('previews integration-only panel changes without mutating the discovered panel', function (): void {
    $panelPath = writeSetupCommandPanelProvider();

    artisanCommand('capell:admin-setup', [
        '--integration-only' => true,
        '--configurators' => 'Admin/Configurators=App\\Admin\\Configurators,Modules/Blog=Modules\\Blog\\Filament',
        '--no-colors' => true,
        '--no-widgets' => true,
        '--no-navigation' => true,
        '--preview' => true,
        '--force' => true,
    ])
        ->expectsOutput('Admin setup: discovering Filament panels')
        ->expectsOutput('Admin setup: integrating Capell Admin into Filament')
        ->expectsOutputToContain('Filament panel: app/Providers/Filament/AdminPanelProvider.php')
        ->expectsOutput('Preview mode: no files were written.')
        ->expectsOutput('Admin setup complete.')
        ->assertExitCode(0);

    expect(File::get($panelPath))
        ->not->toContain('CapellAdminPlugin::make()')
        ->not->toContain('CapellAdmin::getWidgets()')
        ->not->toContain('CapellAdmin::getNavigationItems()');
});

it('reports missing panels when only integration was requested', function (): void {
    cleanupSetupCommandPanelProvider();

    artisanCommand('capell:admin-setup', [
        '--integration-only' => true,
        '--force' => true,
    ])
        ->expectsOutput('Admin setup: discovering Filament panels')
        ->expectsOutput('Admin setup: integrating Capell Admin into Filament')
        ->expectsOutputToContain('No Filament panels found. Run php artisan make:filament-panel first.')
        ->assertExitCode(1);
});

it('lets operators decline panel integration after content setup', function (): void {
    $user = User::factory()->createOne(['email' => 'decline-panel@example.com']);
    writeSetupCommandPanelProvider();
    $integrateAdminPanelSpy = bindFakeAction(IntegrateCapellAdminPanelAction::class);

    artisanCommand('capell:admin-setup', [
        '--url' => 'https://example.test',
        '--user' => $user->email,
        '--languages' => 'en',
        '--sites' => 'Main Site',
        '--assets' => [
            'resources/css/app.css',
        ],
        '--skip-shield' => true,
    ])
        ->expectsOutput('Admin setup: discovering Filament panels')
        ->expectsConfirmation('Integrate Capell Admin into Filament?', 'no')
        ->expectsOutput('Admin setup complete.')
        ->assertExitCode(0);

    expect($integrateAdminPanelSpy->called)->toBeFalse();
});

it('completes content setup when no Filament panel exists yet', function (): void {
    bindFakeAction(DiscoverFilamentPanelsAction::class, collect());

    $user = User::factory()->createOne(['email' => 'no-panel@example.com']);

    artisanCommand('capell:admin-setup', [
        '--url' => 'https://example.test',
        '--user' => $user->email,
        '--languages' => 'en',
        '--sites' => 'Main Site',
        '--assets' => [
            'resources/css/app.css',
        ],
        '--skip-shield' => true,
    ])
        ->expectsOutput('Admin setup: discovering Filament panels')
        ->expectsOutput('Admin setup complete.')
        ->assertExitCode(0);
});

it('prompts for a target panel when more than one Filament panel is discovered', function (): void {
    writeSetupCommandPanelProvider();
    $blogPanelPath = app_path('Providers/Filament/BlogPanelProvider.php');
    File::put($blogPanelPath, str_replace(
        ['AdminPanelProvider', "->id('admin')", "->path('admin')"],
        ['BlogPanelProvider', "->id('blog')", "->path('blog')"],
        AdminPanelProviderFixtures::clean(),
    ));

    artisanCommand('capell:admin-setup', [
        '--integration-only' => true,
        '--preview' => true,
    ])
        ->expectsQuestion(
            'Which Filament panel should Capell Admin integrate with?',
            'blog: app/Providers/Filament/BlogPanelProvider.php',
        )
        ->expectsOutputToContain('Filament panel: app/Providers/Filament/BlogPanelProvider.php')
        ->expectsOutput('Preview mode: no files were written.')
        ->assertExitCode(0);
});

it('normalises array based setup list options for install callers', function (): void {
    $command = new SetupCommand;
    $command->setLaravel(app());

    $input = new ArrayInput([
        '--languages' => [' en ', '', 'fr'],
        '--sites' => [' Main Site ', ' ', 'Knowledge'],
    ], $command->getDefinition());
    $input->setInteractive(false);

    $inputProperty = new ReflectionProperty($command, 'input');
    $inputProperty->setValue($command, $input);

    $outputProperty = new ReflectionProperty($command, 'output');
    $outputProperty->setValue($command, new OutputStyle($input, new BufferedOutput));

    $parseListOption = new ReflectionMethod($command, 'parseListOption');

    expect($parseListOption->invoke($command, 'languages'))->toBe(['en', 'fr'])
        ->and($parseListOption->invoke($command, 'sites'))->toBe(['Main Site', 'Knowledge']);
});

it('validates setup command option parsing and path helpers before mutating the app', function (): void {
    $command = new SetupCommand;
    $command->setLaravel(app());

    $input = new ArrayInput([
        '--configurators' => 'Admin/Configurators=App\\Admin\\Configurators,Modules/Blog=Modules\\Blog\\Filament',
        '--languages' => ' en, ,fr ',
    ], $command->getDefinition());
    $input->setInteractive(false);

    $inputProperty = new ReflectionProperty($command, 'input');
    $inputProperty->setValue($command, $input);

    $outputProperty = new ReflectionProperty($command, 'output');
    $outputProperty->setValue($command, new OutputStyle($input, new BufferedOutput));

    $parseConfiguratorOption = new ReflectionMethod($command, 'parseConfiguratorOption');
    $parseListOption = new ReflectionMethod($command, 'parseListOption');
    $normalisePath = new ReflectionMethod($command, 'normalisePath');
    $tailwindSourceForPackagePath = new ReflectionMethod($command, 'tailwindSourceForPackagePath');

    $packagePath = storage_path('framework/testing/setup-command-path-package');
    File::ensureDirectoryExists($packagePath);

    try {
        expect($parseConfiguratorOption->invoke($command))->toBe([
            ['in' => 'Admin/Configurators', 'for' => 'App\\Admin\\Configurators'],
            ['in' => 'Modules/Blog', 'for' => 'Modules\\Blog\\Filament'],
        ])
            ->and($parseListOption->invoke($command, 'languages'))->toBe(['en', 'fr'])
            ->and($normalisePath->invoke($command, '/var/www/../app//./resources/views'))->toBe('/var/app/resources/views')
            ->and($tailwindSourceForPackagePath->invoke($command, $packagePath, resource_path('css/filament/admin/theme.css')))->toBeNull()
            ->and($tailwindSourceForPackagePath->invoke($command, $packagePath, resource_path('css/filament/admin/theme.css'), false))
            ->toContain("@source '");
    } finally {
        File::deleteDirectory($packagePath);
    }
});

it('fails non-interactive setup when the requested admin user is missing', function (): void {
    $command = new SetupCommand;
    $command->setLaravel(app());

    $input = new ArrayInput([
        '--user' => 'missing-user@example.test',
    ], $command->getDefinition());
    $input->setInteractive(false);

    $inputProperty = new ReflectionProperty($command, 'input');
    $inputProperty->setValue($command, $input);

    $outputProperty = new ReflectionProperty($command, 'output');
    $outputProperty->setValue($command, new OutputStyle($input, new BufferedOutput));

    $resolveUser = new ReflectionMethod($command, 'resolveUser');

    expect(fn (): mixed => $resolveUser->invoke($command))
        ->toThrow(RuntimeException::class, "User with identifier 'missing-user@example.test' was not found.");
});

it('registers tailwind sources for installed Capell packages with views during setup', function (): void {
    $themeCss = resource_path('css/filament/admin/theme.css');
    $originalContents = File::exists($themeCss) ? File::get($themeCss) : null;

    File::ensureDirectoryExists(dirname($themeCss));
    File::put($themeCss, <<<'CSS'
@import '../../../../vendor/filament/filament/resources/css/theme.css';

@source '../../../../app/Filament/**/*';
@source '../../../../resources/views/filament/**/*';
CSS);

    CapellCore::registerPackage('capell-app/core');

    try {
        $command = new SetupCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $method = new ReflectionMethod($command, 'registerTailwindSources');
        $method->invoke($command);

        $contents = File::get($themeCss);

        expect($contents)
            ->toContain('/admin/resources/views/**/*.blade.php')
            ->toContain('/core/resources/views/**/*.blade.php')
            ->toContain('/frontend/resources/views/**/*.blade.php')
            ->toContain("@source '../../../../storage/capell/tailwind-classes.txt';");

        $method->invoke($command);

        expect(substr_count(File::get($themeCss), '/admin/resources/views/**/*.blade.php'))
            ->toBe(1);
    } finally {
        if ($originalContents === null) {
            File::delete($themeCss);
        } else {
            File::put($themeCss, $originalContents);
        }
    }
});

it('registers tailwind sources from Capell package metadata paths during setup', function (): void {
    $themeCss = resource_path('css/filament/admin/theme.css');
    $originalContents = File::exists($themeCss) ? File::get($themeCss) : null;
    $packagePath = storage_path('framework/testing/capell-registered-tailwind-package');

    File::ensureDirectoryExists(dirname($themeCss));
    File::ensureDirectoryExists($packagePath . '/resources/views');
    File::put($packagePath . '/composer.json', json_encode([
        'name' => 'capell-app/registered-tailwind-package',
    ], JSON_THROW_ON_ERROR));
    File::put($packagePath . '/resources/views/example.blade.php', '<div></div>');
    File::put($themeCss, <<<'CSS'
@import '../../../../vendor/filament/filament/resources/css/theme.css';
CSS);

    CapellCore::registerPackage(
        'capell-app/registered-tailwind-package',
        type: PackageTypeEnum::Plugin,
        path: $packagePath,
    );

    try {
        $command = new SetupCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $method = new ReflectionMethod($command, 'registerTailwindSources');
        $method->invoke($command);

        expect(File::get($themeCss))->toContain('/capell-registered-tailwind-package/resources/views/**/*.blade.php');
    } finally {
        File::deleteDirectory($packagePath);

        if ($originalContents === null) {
            File::delete($themeCss);
        } else {
            File::put($themeCss, $originalContents);
        }
    }
});

function registerInstalledSetupTheme(string $packageName, string $themeKey): void
{
    CapellCore::registerManifestPackage(CapellManifestData::fromArray([
        'manifest-version' => 3,
        'name' => $packageName,
        'slug' => str($packageName)->after('/')->toString(),
        'displayName' => str($packageName)->after('/')->headline()->toString(),
        'kind' => 'theme',
        'capellApiVersion' => '^1.0',
        'version' => '1.0.0',
        'product' => [
            'group' => 'Theme',
            'tier' => 'free',
        ],
        'performance' => [
            'cacheSafety' => [
                'cacheable' => true,
                'variesBy' => [],
                'sensitiveOutput' => false,
                'invalidationSources' => [],
                'queueInvalidation' => false,
            ],
        ],
        'surfaces' => ['frontend'],
        'themeKey' => $themeKey,
    ]));
    CapellCore::forcePackageInstalled($packageName);
}

it('updates generated filament theme css for tailwind four during setup', function (): void {
    $themeCss = resource_path('css/filament/admin/theme.css');
    $originalContents = File::exists($themeCss) ? File::get($themeCss) : null;

    File::ensureDirectoryExists(dirname($themeCss));
    File::put($themeCss, <<<'CSS'
@import '../../../../vendor/filament/filament/resources/css/base.css';
@import '../../../../vendor/awcodes/filament-curator/resources/css/plugin.css';

@tailwind base;
@tailwind components;
@tailwind utilities;
@tailwind variants;

@config 'tailwind.config.js';
CSS);

    try {
        $command = new SetupCommand;
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

        $method = new ReflectionMethod($command, 'registerTailwindSources');
        $method->invoke($command);

        $contents = File::get($themeCss);

        expect($contents)
            ->toContain("@import 'tailwindcss';")
            ->toContain("@config './tailwind.config.js';")
            ->not->toContain('@tailwind base;')
            ->not->toContain('@tailwind utilities;');
    } finally {
        if ($originalContents === null) {
            File::delete($themeCss);
        } else {
            File::put($themeCss, $originalContents);
        }
    }
});

function writeSetupCommandPanelProvider(): string
{
    $panelPath = app_path('Providers/Filament/AdminPanelProvider.php');

    File::ensureDirectoryExists(dirname($panelPath));
    File::put($panelPath, AdminPanelProviderFixtures::clean());

    return $panelPath;
}

function cleanupSetupCommandPanelProvider(): void
{
    File::delete([
        app_path('Providers/Filament/AdminPanelProvider.php'),
        app_path('Providers/Filament/BlogPanelProvider.php'),
    ]);

    foreach ([
        app_path('Providers/Filament'),
        app_path('Providers'),
    ] as $directoryPath) {
        if (File::isDirectory($directoryPath) && count(scandir($directoryPath) ?: []) === 2) {
            File::deleteDirectory($directoryPath);
        }
    }
}
