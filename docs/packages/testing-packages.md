# Testing Packages

Package tests should prove the package boots, registers its extension points, and owns its behavior.

Capell packages are tested with [Orchestra Testbench](https://packages.tools/testbench), which boots a throwaway Laravel application around the package. Nothing in this page needs a host application.

<a id="standalone-package-harness"></a>

## Standalone package harness

Use this section when your package lives in its own repository. The examples elsewhere on this page assume the harness below already exists.

`php artisan capell:make-extension` scaffolds most of it, but the generated `TestCase` registers **only your own provider** — it does not boot Capell. Anything that touches Capell registries, settings, or migrations needs `CapellServiceProvider` added, as shown below.

### 1. Development dependencies

```bash
composer require --dev \
  orchestra/testbench:^11.0 \
  pestphp/pest:^5.0 \
  pestphp/pest-plugin-laravel:^5.0
```

Match the Capell line you target: Capell 1.x runs PHP 8.4+, Laravel 13, Filament `~5.6.8`, PHPUnit 13, and Pest 5. Pest 4 and Pest 5 are not interchangeable here.

### 2. `phpunit.xml.dist`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Package">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
    <php>
        <ini name="memory_limit" value="1G"/>
        <env name="APP_ENV" value="testing"/>
        <env name="APP_KEY" value="12345678901234567890123456789012"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array" force="true"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="MAIL_MAILER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync" force="true"/>
        <env name="SESSION_DRIVER" value="array" force="true"/>
    </php>
</phpunit>
```

An `<ini>` entry here overrides any `-d memory_limit` on the command line, and it applies to parallel workers too. Set the limit in this file rather than on the command line.

### 3. Base `TestCase`

```php
<?php

declare(strict_types=1);

namespace Acme\AnnouncementBar\Tests;

use Acme\AnnouncementBar\Providers\AnnouncementBarServiceProvider;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Providers\CapellServiceProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionClass;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelSettings\LaravelSettingsServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

abstract class TestCase extends Orchestra
{
    use LazilyRefreshDatabase;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders(mixed $app): array
    {
        return [
            LaravelDataServiceProvider::class,
            PermissionServiceProvider::class,
            CapellServiceProvider::class,
            LaravelSettingsServiceProvider::class,
            AnnouncementBarServiceProvider::class,
        ];
    }

    protected function defineEnvironment(mixed $app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        // Must happen before the application boots: the provider's
        // bootInstalledPackage() gate is evaluated during boot.
        CapellCore::forcePackageInstalled('acme/announcement-bar');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();

        $coreMigrationPath = $this->coreMigrationPath();

        foreach (CapellCore::getMigrations() as $migrationName) {
            $this->loadMigrationsFrom(sprintf('%s/%s.php', $coreMigrationPath, $migrationName));
        }
    }

    private function coreMigrationPath(): string
    {
        $providerFile = (new ReflectionClass(CapellServiceProvider::class))->getFileName();

        return dirname((string) $providerFile, 3) . '/database/migrations';
    }
}
```

`CapellCore::getMigrations()` returns migration **names**, not paths — `list<string>` such as `2026_05_10_190832_05_create_sites_table`. Resolve them against the installed core package directory, as above. Passing the bare names to `loadMigrationsFrom()` silently loads nothing and every Capell table is then missing.

Load only the core migrations your package actually needs if the full set makes the suite slow; the list is ordered and the later files depend on the earlier ones.

Provider order matters, and `LaravelSettingsServiceProvider` must come **after** `CapellServiceProvider`. Capell's settings bootstrapper pushes each installed package's settings class into `config('settings.settings')` while Capell boots; Spatie's provider reads that config to register its bindings. Reverse the two and your settings class is never bound, so `app(YourSettings::class)` fails.

Data and permissions providers go before Capell, which resolves their bindings during boot. This is the order Capell's own base test case uses.

**Timing matters more than the call itself.** `AbstractPackageServiceProvider` evaluates the installed gate for `bootInstalledPackage()` during application boot. Calling `CapellCore::forcePackageInstalled()` from the test body — or from `setUp()` after `parent::setUp()` — is too late: the gate has already resolved to "not installed", so your bridge, settings, and render hooks were never registered and every registration assertion fails. Put it in `defineEnvironment()` (or `getEnvironmentSetUp()`), as above. Capell's own admin test case does exactly this.

`forcePackageInstalled()` is marked `@internal` in Capell's source. It is nonetheless the mechanism Capell's own test suite uses and the only supported way to simulate an installed package in tests; treat its signature as stable-but-not-guaranteed and keep the call in one place in your harness.

### Binding a settings class in tests

Testbench has no manifest discovery, so nothing reads your `capell.json` `settings` entry and `app(YourSettings::class)` stays unbound even after `forcePackageInstalled()`. `SettingsBootstrapper` builds `config('settings.settings')` from each registered package's `setting` property, with no install-state check.

The reliable route is to override `packageSettingClass()` on your provider, which `AbstractPackageServiceProvider` reads while registering package metadata:

```php
protected function packageSettingClass(): ?string
{
    return AnnouncementBarSettings::class;
}
```

That works in tests and in a real application, and keeps the manifest entry as the declaration of record.

Add the providers for the surfaces your package actually uses:

| Your package | Also register |
| ------------ | ------------- |
| Registers render hooks or renders public HTML | `Capell\Frontend\Providers\FrontendServiceProvider` |
| Registers admin pages, resources, or widgets | The Filament set (`FilamentServiceProvider`, `FormsServiceProvider`, `SchemasServiceProvider`, `SupportServiceProvider`, `TablesServiceProvider`, `WidgetsServiceProvider`, `ActionsServiceProvider`, `NotificationsServiceProvider`), plus `Livewire\LivewireServiceProvider` and a Filament panel provider |

`RenderHookRegistry` is bound by the frontend provider, so a package that calls `app(RenderHookRegistry::class)` during boot fails without it.

Registering the frontend provider gives you the binding, but **not** a renderable site. A test that asserts on `$this->get('/')` also needs a site, language, and published page fixture; without them there is no route to hit. Assert on the registry for hook-registration tests, and build the fixture only for the one or two tests that genuinely need rendered HTML:

```php
it('registers the announcement render hook', function (): void {
    CapellCore::forcePackageInstalled('acme/announcement-bar');

    expect(resolve(RenderHookRegistry::class)->get(RenderHookLocation::HeaderAfter))
        ->not->toBeEmpty();
});
```

### 4. Settings groups in tests

Spatie settings need their migration applied before the group hydrates, or every property read throws. Apply your package's settings migrations in `defineDatabaseMigrations()`:

```php
protected function defineDatabaseMigrations(): void
{
    $this->loadLaravelMigrations();

    $coreMigrationPath = $this->coreMigrationPath();

    foreach (CapellCore::getMigrations() as $migrationName) {
        $this->loadMigrationsFrom(sprintf('%s/%s.php', $coreMigrationPath, $migrationName));
    }

    foreach (glob(__DIR__ . '/../database/settings/*.php') ?: [] as $settingsMigration) {
        (require $settingsMigration)->up();
    }
}
```

This replaces the `defineDatabaseMigrations()` from step 3 — it is the same method with the settings loop appended, not a second one. `__DIR__` is your `tests/` directory, so `../database/settings` is the package's own settings directory; get the depth wrong and `glob()` returns an empty array, the loop silently does nothing, and every settings property read then throws.

### 5. `tests/Pest.php`

```php
<?php

declare(strict_types=1);

use Acme\AnnouncementBar\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');
```

Then run the suite from the package root:

```bash
vendor/bin/pest
```

### What is not available outside the monorepo

Capell's internal test helpers live under the `Capell\Tests\` namespace, which is mapped in this repository's `autoload-dev` only. `CreatesAdminUser`, `TestingFrontend`, `IsolatedTestbenchSkeleton`, `PackageTestDatabaseGuard`, and the `Capell\Tests\Support\Fakes\*` doubles are **not** shipped to third-party packages. Do not reference them; write the equivalent fixture in your own package.

`CapellCore::registerManifestPackage()`, `CapellCore::getMigrations()`, and `CapellCore::getSettingMigrations()` are shipped API and safe to use. `CapellCore::forcePackageInstalled()` is marked `@internal` but is the supported test mechanism, as noted above.

## Test Case

Create a package test case that registers only the providers the package needs.

```php
protected function getPackageProviders($app): array
{
    return [
        \Capell\Core\Providers\CapellServiceProvider::class,
        \Capell\Admin\Providers\AdminServiceProvider::class,
        \Capell\Example\Providers\ExampleServiceProvider::class,
        \Capell\Example\Providers\AdminServiceProvider::class,
    ];
}
```

Force packages installed in tests when testing installed-only behavior:

```php
CapellCore::forcePackageInstalled('capell-app/example');
```

## Manifest Tests

Every package with `capell.json` should test that required manifest fields exist and providers are loadable.

## Provider Tests

Provider tests should assert:

- package metadata is registered.
- admin pages are registered through `CapellAdmin`.
- dashboard Filament widgets are registered in the correct dashboard slot.
- settings schemas are registered when present.

```php
it('registers package metadata', function (): void {
    $package = CapellCore::getPackage('capell-app/example');

    expect($package->name)->toBe('capell-app/example');
});
```

## Action Tests

Test Actions directly. Avoid HTTP tests unless the route or Filament page behavior is the subject.

```php
it('builds the package output data', function (): void {
    $data = BuildExampleOutputAction::run($input);

    expect($data)->toBeInstanceOf(ExampleOutputData::class);
});
```

## Admin Extension Tests

Test direct registration first, then add one render test for the user-facing surface.

```php
it('tags the page schema extender', function (): void {
    $extenders = collect(app()->tagged(PageSchemaExtender::TAG));

    expect($extenders)
        ->toContain(fn (PageSchemaExtender $extender): bool => $extender instanceof ExamplePageSchemaExtender);
});
```

```php
it('registers an extension settings page', function (): void {
    CapellCore::forcePackageInstalled('capell-app/example');

    expect(resolve(ExtensionPageRegistry::class)->get('capell-app/example'))
        ->toBe(ExampleSettingsPage::class);
});
```

## Frontend Output Tests

Any package that renders public HTML needs presence and absence assertions.

```php
it('renders public package output without authoring state', function (): void {
    $response = $this->get('/example-page');

    $response->assertOk();

    expect($response->getContent())
        ->toContain('Expected public copy')
        ->not->toContain('data-capell-editor')
        ->not->toContain('field_path')
        ->not->toContain('signed');
});
```

Run Blade view coverage when adding or changing package views:

```bash
composer coverage:blade
```

The check is ratcheted by `tests/BladeCoverage/baseline.json` and only counts views Laravel actually renders. See [Blade view coverage](../development/blade-view-coverage.md). `coverage:blade` is a monorepo Composer script; a standalone package uses its own coverage configuration instead.

## Marketplace Tests

Marketplace-adjacent packages should prove local compatibility and authorization state handling without treating remote metadata as trusted code.

```php
it('records install intent only when an instance is connected', function (): void {
    MarketplaceInstance::factory()->create();

    $acquisition = CreateExtensionAcquisitionAction::run($listing);

    expect($acquisition->composerCommand)->toContain('composer require');
});
```

## Architecture Tests

Use arch tests to prevent package boundary regressions:

- package code should not import app-specific classes.
- frontend providers should not import Filament.
- admin providers should not run on frontend-only contexts.
- packages should not import sibling packages unless Composer requires them.

## Narrow Commands

From a standalone package repository, run your own suite:

```bash
vendor/bin/pest
```

Inside this monorepo, run the package suite against the root configuration during implementation:

```bash
vendor/bin/pest packages/example/tests --configuration=phpunit.xml
```

Run the host suite only when the package touches shared contracts, public rendering, install/upgrade, or admin boot:

```bash
composer test
```

## Next

- [Build an extension end to end](build-extension-end-to-end.md)
- [Extension point API reference](extension-point-api-reference.md)
- [Public HTML safety](../frontend/public-html-safety.md)
