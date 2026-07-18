# Service Providers

Split service providers by runtime context. Providers should wire registrations, not contain business logic.

## Lifecycle Provider Buckets

Manifest v3 separates lifecycle-safe providers from runtime providers:

- `metadata` and `install` providers are lifecycle-safe.
- `runtime`, `admin`, and `frontend` providers are active-runtime providers and only load for enabled packages.
- `admin` providers also load in console context for enabled packages so admin-owned commands can resolve their dependencies.
- `frontend` providers load only in frontend context.

Do not put Filament resources, dashboard Filament widgets, render hooks, frontend middleware, or model behaviour in `metadata` or `install` providers.

## Runtime Provider

Use the runtime provider for models, config, routes shared across enabled contexts, and container bindings. Normal package metadata belongs in `capell.json`; provider-side `CapellCore::registerPackage()` is only for trusted first-party bootstrap and compatibility paths.

Providers extending `AbstractPackageServiceProvider` should put ordinary package registrations in `bootInstalledPackage()`. `bootPackage()` is ungated and is reserved for work genuinely needed before installation or during discovery.

```php
<?php

declare(strict_types=1);

namespace Capell\Example\Providers;

use Capell\Core\Support\Packages\AbstractPackageServiceProvider;
use Spatie\LaravelPackageTools\Package;

final class ExampleServiceProvider extends AbstractPackageServiceProvider
{
    public static string $name = 'capell-example';

    public static string $packageName = 'capell-app/example';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(self::$name)
            ->hasConfigFile(self::$name)
            ->hasTranslations()
            ->hasViews(self::$name);
    }

    protected function bootInstalledPackage(): self
    {
        // Bind runtime services and register installed package surfaces here.

        return $this;
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

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
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
- Use `bootInstalledPackage()` for installed-only behavior; do not repeat that lifecycle gate in each provider.
- Use contract `TAG` constants for focused contributors and `AdminBridgeRegistry` / `AdminBridgeRegistrar` for grouped admin integration.
- Register package-owned settings through `surface()` / `PackageSurfaceRegistrar`; register settings supplied by an external admin integration through `AdminBridgeRegistrar`.
- Choose singleton or scoped bindings from the state lifetime. Mutable singletons must implement and be tagged as `Resettable`.
- Do not load frontend render code on admin-only packages.
- Do not register Filament pages from metadata or install providers.
- Do not require a `capell.test` hostname or testing-only environment gate for normal provider wiring.

For registry APIs, helper naming, lifetime rules, and examples, see [Package provider conventions](../development/package-provider-conventions.md).
