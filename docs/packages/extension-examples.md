# Extension Examples

These examples show the usual shape of a package that adds admin UI, page fields, settings, frontend output, runtime assets, Tailwind sources, cache invalidation, and lifecycle work.

Keep examples this small in real packages too. Put the wiring in service providers and bridges, keep business behaviour in Actions, and keep public frontend output free from admin-only context.

## Admin Bridge

Use an admin bridge when a package contributes more than one admin concern. The bridge keeps the package's Filament surface in one predictable place.

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Admin;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Auth\Authenticatable;
use Vendor\Example\Filament\Resources\ExampleReportResource;
use Vendor\Example\Filament\Settings\ExampleSettingsSchema;
use Vendor\Example\Filament\Widgets\ExampleStatusWidget;
use Vendor\Example\Settings\ExampleSettings;

final class ExampleAdminBridge implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->settingsMetadata(new SettingsGroupMetadata(
            group: 'example',
            label: 'capell-example::settings.title',
            icon: Heroicon::OutlinedCog6Tooth,
            navigationGroup: 'capell-admin::navigation.group_system',
            packageName: 'vendor/example',
        ));

        $registrar->settingsClass('example', ExampleSettings::class);
        $registrar->settingsSchema('example', ExampleSettingsSchema::class);
        $registrar->resource(ExampleReportResource::class, group: 'ExampleReport');
        $registrar->filamentDashboardWidget(ExampleStatusWidget::class, DashboardEnum::Main);

        $registrar->userMenuItem(
            key: 'example.reports',
            label: fn (): string => __('capell-example::navigation.reports'),
            icon: Heroicon::OutlinedChartBar,
            url: fn (): string => ExampleReportResource::getUrl(),
            visible: fn (?Authenticatable $user): bool => $user?->can('viewAny', ExampleReportResource::getModel()) ?? false,
            sort: 60,
            group: 'example',
        );
    }
}
```

Register and boot the bridge from the package service provider:

```php
<?php

declare(strict_types=1);

namespace Vendor\Example;

use Capell\Admin\Facades\CapellAdmin;
use Illuminate\Support\ServiceProvider;
use Vendor\Example\Admin\ExampleAdminBridge;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        CapellAdmin::registerAdminBridge('vendor/example', ExampleAdminBridge::class);
        CapellAdmin::bootAdminBridges('vendor/example');
    }
}
```

Use direct admin surface contributions only for small, one-off registrations:

```php
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;

CapellAdmin::contributeToAdminSurface(
    AdminSurfaceContributionData::resource(ExampleReportResource::class, group: 'ExampleReport'),
);
```

## Page Schema Extender

Use a schema extender when a package needs to add package-owned fields to the page editor. Prefer the abstract base class so the package only implements the hook it needs.

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Admin\Schemas;

use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Capell\Admin\Support\Schemas\AbstractPageSchemaExtender;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class ExamplePageSchemaExtender extends AbstractPageSchemaExtender
{
    public function extendTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook): array
    {
        return match ($hook) {
            PageTranslationSchemaHookEnum::AfterTitle => [
                TextInput::make('example_subtitle')
                    ->label(__('capell-example::fields.subtitle'))
                    ->maxLength(160),
            ],
            default => [],
        };
    }
}
```

Tag the extender from the package admin provider:

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Providers;

use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Illuminate\Support\ServiceProvider;
use Vendor\Example\Admin\Schemas\ExamplePageSchemaExtender;

final class ExampleAdminServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->tag([ExamplePageSchemaExtender::class], PageSchemaExtender::TAG);
    }
}
```

When an admin bridge already owns the package admin surface, register the same class through the bridge instead:

```php
$registrar->schemaExtender(ExamplePageSchemaExtender::class, PageSchemaExtender::TAG);
```

## Package Settings

Settings classes should hold the persisted values. Settings schemas should only describe the Filament form.

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Settings;

use Capell\Core\Contracts\SettingsContract;
use Spatie\LaravelSettings\Settings;
use Vendor\Example\Filament\Settings\ExampleSettingsSchema;

final class ExampleSettings extends Settings implements SettingsContract
{
    public bool $enabled;

    public string $tracking_label;

    public static function group(): string
    {
        return 'example';
    }

    public static function schema(): string
    {
        return ExampleSettingsSchema::class;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Filament\Settings;

use Capell\Admin\Filament\Contracts\HasSchema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ExampleSettingsSchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('capell-example::settings.general'))
                ->columnSpanFull()
                ->schema([
                    Toggle::make('enabled')
                        ->label(__('capell-example::settings.enabled')),
                    TextInput::make('tracking_label')
                        ->label(__('capell-example::settings.tracking_label'))
                        ->maxLength(120),
                ])
                ->columns(2),
        ];
    }
}
```

Register direct settings contributions from the package provider when an admin bridge is too much for the package:

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Providers;

use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\ServiceProvider;
use Vendor\Example\Filament\Settings\ExampleSettingsSchema;
use Vendor\Example\Settings\ExampleSettings;

final class ExampleAdminServiceProvider extends ServiceProvider
{
    public function boot(SettingsSchemaRegistry $settings): void
    {
        $settings->registerMetadata(new SettingsGroupMetadata(
            group: 'example',
            label: 'capell-example::settings.title',
            icon: Heroicon::OutlinedCog6Tooth,
            navigationGroup: 'capell-admin::navigation.group_system',
            packageName: 'vendor/example',
        ));

        $settings->registerSettingsClass('example', ExampleSettings::class);
        $settings->register('example', ExampleSettingsSchema::class, 'general');
    }
}
```

## Frontend Render Hook

Render hooks are for ordinary public markup: meta tags, structured data, small assets, or package-owned frontend fragments.

```php
<?php

declare(strict_types=1);

namespace Vendor\Example;

use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;
use Illuminate\Support\ServiceProvider;

final class ExampleFrontendServiceProvider extends ServiceProvider
{
    public function boot(RenderHookRegistry $renderHooks): void
    {
        $renderHooks->register(
            RenderHookLocation::HeadClose,
            fn (): string => view('capell-example::frontend.head-meta')->render(),
            priority: 20,
        );
    }
}
```

Never put authoring metadata in a render hook. Public HTML must not expose editable markers, model IDs, field paths, permissions, package internals, or signed admin URLs. Admin editing controls belong in post-load admin-only responses, not cached page HTML.

## Frontend Runtime Assets

Register runtime CSS and JavaScript through `FrontendResourceRegistry` when a widget, section, or package feature needs public assets at render time. Use package-owned build paths so the generated manifest can keep assets grouped by extension.

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Providers;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Frontend\Support\Assets\FrontendResourceRegistry;
use Illuminate\Support\ServiceProvider;

final class ExampleFrontendServiceProvider extends ServiceProvider
{
    public function boot(FrontendResourceRegistry $resources): void
    {
        $resources
            ->group('vendor-example.gallery')
            ->css('resources/css/gallery.css', buildPath: 'vendor/example')
            ->js(
                source: 'resources/js/gallery.js',
                buildPath: 'vendor/example',
                loading: PresentationLoadingStrategy::Visible,
                defer: true,
            );
    }
}
```

Reference the group from package-owned widget or layout registration, then let Capell decide whether the asset is eager, visible, or lazy for the active render profile. Do not inline admin-only boot data into these assets.

## Tailwind Assets

Packages that ship frontend Blade or CSS should register their Tailwind sources and imports. This lets the host build include package classes without hand-editing the app's Tailwind config.

```php
<?php

declare(strict_types=1);

namespace Vendor\Example;

use Capell\Core\Support\Tailwind\TailwindAssetsRegistry;
use Illuminate\Support\ServiceProvider;

final class ExampleThemeServiceProvider extends ServiceProvider
{
    public function boot(TailwindAssetsRegistry $tailwindAssets): void
    {
        $tailwindAssets
            ->registerSource(__DIR__ . '/../resources/views/**/*.blade.php', 'vendor/example')
            ->registerImport(__DIR__ . '/../resources/css/frontend.css', 'vendor/example');
    }
}
```

## Cache Invalidation

Register model-to-cache dependencies during boot, then call the registry from a model observer. Use exact keys when you can. A pattern containing `*` intentionally falls back to a full `capell-frontend` tag flush.

```php
<?php

declare(strict_types=1);

namespace Vendor\Example;

use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Illuminate\Support\ServiceProvider;
use Vendor\Example\Models\ExampleReport;
use Vendor\Example\Observers\ExampleReportObserver;

final class ExampleCacheServiceProvider extends ServiceProvider
{
    public function boot(CacheInvalidationRegistry $cacheInvalidation): void
    {
        $cacheInvalidation->registerDependency(
            modelClass: ExampleReport::class,
            cachePatterns: ['example-reports', 'example-report-*'],
        );

        ExampleReport::observe(ExampleReportObserver::class);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Observers;

use Capell\Frontend\Support\Cache\CacheInvalidationRegistry;
use Vendor\Example\Models\ExampleReport;

final class ExampleReportObserver
{
    public function saved(ExampleReport $exampleReport): void
    {
        resolve(CacheInvalidationRegistry::class)->invalidateForModel(ExampleReport::class);
    }

    public function deleted(ExampleReport $exampleReport): void
    {
        resolve(CacheInvalidationRegistry::class)->invalidateForModel(ExampleReport::class);
    }
}
```

## Lifecycle Actions

Install, setup, and after-install work must live in Actions so CLI, installer, and Marketplace flows all run the same code path.

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Actions;

use Capell\Core\Contracts\PackageLifecycleAction;
use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\PackageData;
use Lorisleiva\Actions\Concerns\AsObject;

final class InstallExamplePackageAction implements PackageLifecycleAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(PackageData $package, array $arguments = [], ?ProgressReporter $reporter = null): void
    {
        $reporter?->info('Preparing example package.');

        // Run package-owned setup here: migrations, seed data, settings defaults,
        // or cache rebuilds that are safe to repeat.

        $reporter?->success('Example package is ready.');
    }
}
```

Declare lifecycle classes in `capell.json`:

```json
{
    "actions": {
        "install": "Vendor\\Example\\Actions\\InstallExamplePackageAction"
    }
}
```

Keep console commands as thin developer adapters. They should call the Action instead of duplicating lifecycle work.

## Public Safety Tests

Every package that writes public HTML should have a regression test for anonymous and non-admin output. Assert the content you expect, then run the public safety guard over the same response.

```php
<?php

declare(strict_types=1);

use App\Models\User;
use Capell\Frontend\Actions\AssertPublicHtmlContainsNoAuthoringSurfaceAction;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Route::get('/example-package-card', fn (): Response => response(
        view('capell-example::frontend.card', [
            'title' => 'Public example',
        ])->render(),
        Response::HTTP_OK,
        ['Content-Type' => 'text/html; charset=UTF-8'],
    ));
});

it('keeps anonymous package output public safe', function (): void {
    $response = $this->get('/example-package-card');

    $response->assertOk()
        ->assertSee('Public example')
        ->assertDontSee('data-capell-authoring', false)
        ->assertDontSee('/admin/pages/1/edit', false);

    AssertPublicHtmlContainsNoAuthoringSurfaceAction::run($response->baseResponse);
});

it('keeps non admin package output public safe', function (): void {
    $response = $this
        ->actingAs(User::factory()->createOne())
        ->get('/example-package-card');

    $response->assertOk()
        ->assertSee('Public example')
        ->assertDontSee('field_path', false)
        ->assertDontSee('model_id', false);

    AssertPublicHtmlContainsNoAuthoringSurfaceAction::run($response->baseResponse);
});
```

Use `AssertPublicRenderContractAction` instead when the package test is exercising a full public render response, static artifact generation, or a response renderer. That guard also preserves the inspection metadata used by the frontend runtime.

## Before Shipping

Run the smallest checks that prove the package contract:

```bash
composer test -- --filter=Example
php artisan capell:extension-audit vendor/example
php artisan capell:package-cache
```

Add a public rendering test if the package writes frontend HTML. That test should assert that anonymous and non-admin responses contain the public markup you expect and none of the admin-only authoring surface.
