# Service Providers

Split service providers by runtime context. Providers should wire registrations, not contain business logic.

## Lifecycle Provider Buckets

Manifest v3 separates lifecycle-safe providers from runtime providers:

- `metadata` and `install` providers are lifecycle-safe. Public processes load `metadata`, but deliberately exclude `install`.
- `runtime`, `admin`, and `frontend` providers are active-runtime providers and only load for enabled packages.
- `admin` providers also load in console context for enabled packages so admin-owned commands can resolve their dependencies.
- `frontend` providers load in every enabled role, including authoring previews.

The immutable `public` runtime role loads enabled `runtime`, `frontend`, and `auth` buckets plus `metadata`; it excludes `install` and `admin`. The `combined` and `authoring` roles load every bucket, and authoring retains Frontend for real previews. See [Runtime roles](../operations/runtime-roles.md).

Do not put Filament resources, dashboard Filament widgets, render hooks, frontend middleware, or model behaviour in `metadata` or `install` providers.

## Runtime Provider

Use the runtime provider for models, config, routes shared across enabled contexts, and container bindings. Normal package metadata belongs in `capell.json`; provider-side `CapellCore::registerPackage()` is only for trusted first-party bootstrap and compatibility paths.

Providers extending `AbstractPackageServiceProvider` should put ordinary installed registrations in `bootInstalledRuntime(): void`. See [Installed runtime lifecycle](../development/installed-runtime-lifecycle.md) for migration and refresh boundaries. `bootPackage()` is ungated and is reserved for work genuinely needed before installation or during discovery.

```php
<?php

declare(strict_types=1);

namespace Capell\Example\Providers;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Spatie\LaravelPackageTools\Package;
use Override;

final class ExampleServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-example';

    public static string $packageName = 'capell-app/example';

    #[Override]
    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile(self::$name)
            ->hasTranslations()
            ->hasViews(self::$name);
    }

    #[Override]
    protected function bootInstalledRuntime(): void
    {
        // Bind runtime services and register installed package surfaces here.
    }
}
```

## Admin Provider

Use the admin provider for Filament pages, resources, widgets, dashboard settings contributors, policies, admin render hooks, and admin-only Livewire components.

```php
<?php

declare(strict_types=1);

namespace Capell\Example\Providers;

use Capell\Admin\Enums\DashboardEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Example\Filament\Pages\ExamplePage;
use Capell\Example\Filament\Widgets\ExampleWidget;
use Illuminate\Support\ServiceProvider;
use Capell\Core\Support\Packages\RegistersInstalledRuntime;
use Override;

final class AdminServiceProvider extends ServiceProvider
{
    use RegistersInstalledRuntime;

    #[Override]
    public function register(): void
    {
        $this->registerInstalledRuntime('capell-app/example', 'admin');
    }

    protected function bootInstalledRuntime(): void
    {
        CapellAdmin::contributeToAdminSurface(
            AdminSurfaceContributionData::page(ExamplePage::class),
        );
        CapellAdmin::registerDashboardFilamentWidget(ExampleWidget::class, DashboardEnum::Main);
    }
}
```

## Frontend Provider

Use the frontend provider for render hooks, frontend routes, frontend Livewire components, and frontend-only view components.

## Console Provider

Use install providers for commands that must be available before the package is enabled. Use runtime providers for scheduled jobs or commands that require the package to be enabled.

```php
public function boot(): void
{
    if ($this->app->runningInConsole()) {
        $this->commands([InstallCommand::class, SetupCommand::class]);
    }
}
```

## Provider Rules

- Keep providers small.
- Call Actions for derived setup work.
- Use `bootInstalledRuntime()` for installed-only behaviour; do not repeat that lifecycle gate or add another once-only flag.
- Use contract `TAG` constants for focused contributors and `AdminBridgeRegistry` / `AdminBridgeRegistrar` for grouped admin integration.
- Register package-owned settings through `surface()` / `PackageSurfaceRegistrar`; register settings supplied by an external admin integration through `AdminBridgeRegistrar`.
- Choose singleton or scoped bindings from the state lifetime. Mutable singletons must implement and be tagged as `Resettable`.
- Do not load frontend render code on admin-only packages.
- Do not register Filament pages from metadata or install providers.
- Do not require a `capell.test` hostname or testing-only environment gate for normal provider wiring.

For registry APIs, helper naming, lifetime rules, and examples, see [Package provider conventions](../development/package-provider-conventions.md).
