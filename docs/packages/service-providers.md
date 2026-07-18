# Service Providers

## Consumer compatibility gate

Changes to package service providers, shared provider base classes, package
registration, or boot lifecycles are not complete when package tests alone
pass. Refresh the local path-repository dependencies in
`/Users/ben/Sites/capell-app`, clear the application caches, and verify that
`https://capell.test/` returns a successful response.

This consumer smoke test is mandatory because companion packages can load
during application bootstrap. A parent/child provider API mismatch therefore
fails the whole application before routes or package-level tests can run.

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

    public function registeringPackage(): void
    {
        // Bind runtime services and register package-owned model metadata here.
        // Do not duplicate normal capell.json metadata in provider code.
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
- Guard installed-only behavior with `CapellCore::isPackageInstalled()` or `CapellCore::isPackageEnabled()` when needed.
- Do not load frontend render code on admin-only packages.
- Do not register Filament pages from metadata or install providers.
