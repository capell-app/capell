# Extending Capell

> **Who is this for?**
> Developers who want to extend Capell with page types, model interceptors, event subscribers, settings, frontend hooks, or add-on packages.

Capell is designed to be extended without modifying its core source code. This document explains the main extension points available to your application and to package authors.

---

## Table of Contents

- [Extension Discovery](#extension-discovery)
- [Page Types And Component Registration](#page-types-and-component-registration)
- [Model Interceptors](#model-interceptors)
- [Schema Hook Extenders](#schema-hook-extenders)
- [Event Registry (Callbacks & Subscribers)](#event-registry-callbacks--subscribers)
- [Render Hooks](#render-hooks)
- [Settings Schema Registry](#settings-schema-registry)
- [Extending core models](#extending-core-models)
- [Package Metadata And Discovery](#package-metadata-and-discovery)
- [Related guides](#related-guides)

---

## Which extension point do I use?

For a website-specific field, follow [Add Client name to a page](../../../docs/getting-started/add-a-custom-page-field.md), then [compare the supported storage and editor approaches](../../../docs/packages/custom-page-fields.md). Registering a page subject alone does not add a field.

Capell offers several extension mechanisms. Pick by what you're extending:

| I want to…                                                         | Use                                                                           | Section                                                                                       |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Add a page subject type                                            | `BlueprintSubjectRegistry::register(new BlueprintSubjectDescriptorData(...))` | [§2](#page-types-and-component-registration)                                                  |
| Register component aliases                                         | `CapellCore::registerComponent()` / `registerComponents()`                    | [§2](#page-types-and-component-registration)                                                  |
| Change seed/install data for pages, layouts, themes, or blueprints | `CapellCore::registerModelInterceptor()`                                      | [§3](#model-interceptors)                                                                     |
| Add fields to an existing page or site edit form                   | Schema hook extender                                                          | [§4](#schema-hook-extenders)                                                                  |
| React to an admin lifecycle event (e.g. after save)                | Event registry callback / subscriber                                          | [§5](#event-registry-callbacks--subscribers)                                                  |
| Block an action based on a condition                               | `ValidationSubscriber`                                                        | [§5](#event-registry-callbacks--subscribers)                                                  |
| Subscribe to fine-grained lifecycle events                         | `SubscriberManager::subscribe()`                                              | [Subscriber Manager](subscriber-manager.md)                                                   |
| Hook into static site export                                       | `StaticSiteExtensionRegistry::register()`                                     | [Static Site Extensions](static-site-extensions.md)                                           |
| Inject HTML into a frontend Blade component                        | Render hook                                                                   | [§6](#render-hooks)                                                                           |
| Register selectable theme header/footer components                 | `ThemeChromeRegistry::register*()`                                            | [Theme Chrome Components](#theme-chrome-components)                                           |
| Add a custom admin toolbar item                                    | Tag with `AdminToolItem::TAG`                                                 | [Admin Tool Registry](../../admin/docs/admin-tool-registry.md)                                |
| Add a dashboard Filament widget programmatically                   | `CapellAdmin::registerDashboardFilamentWidget()`                              | [Dashboard Widget Customization](../../admin/docs/dashboard-widget-customization.md)          |
| Add a tab to the admin Settings page                               | Settings Schema Registry                                                      | [§7](#settings-schema-registry)                                                               |
| Wire model changes to cache flushes                                | `CacheInvalidationRegistry::registerDependency()`                             | [Cache Invalidation](../../../docs/performance/cache-invalidation.md)                         |
| Register runtime frontend assets                                   | `FrontendResourceRegistry` groups / `FrontendResourceContributor::TAG`        | [Frontend Asset Optimization](../../../docs/performance/critical-asset-optimization.md)       |
| Cache an expensive Blade fragment                                  | `@cache(...) ... @endcache` directive                                         | [Fragment Caching](../../../docs/performance/fragment-caching.md)                             |
| Enable conditional responses via ETags                             | `ETagMiddleware` + header handling                                            | [ETag & Conditional Responses](../../../docs/performance/etag-and-conditional-responses.md)   |
| Lazy-load JavaScript for non-critical routes                       | Lazy page hydration                                                           | [Lazy Page Hydration](../../../docs/performance/lazy-page-hydration.md)                       |
| Register Tailwind sources, imports, or plugins                     | `TailwindAssetsRegistry::register*()`                                         | [Tailwind Assets](../../frontend/docs/tailwind-assets.md)                                     |
| Load vendor build assets only for matching pages                   | `VendorAssetConditionRegistry::register()`                                    | [Conditional Vendor Assets](../../frontend/docs/tailwind-assets.md#conditional-vendor-assets) |
| Flush singleton state between Octane requests                      | Implement `Resettable` and tag with `Resettable::TAG`                         | [Long-running workers](#long-running-workers)                                                 |
| Substitute my own subclass for a core model                        | Container binding                                                             | [§8](#extending-core-models)                                                                  |
| Register package metadata                                          | `capell.json` plus manifest registration                                      | [§9](#package-metadata-and-discovery)                                                         |

**Rule of thumb:** prefer targeted extension points over publishing or copying host package files. Published files stop receiving upstream fixes.

---

## Extension Discovery

The simplest way to add optional product capability is to install a package that already owns that feature. Extension packages follow the normal Composer install pattern:

```bash
composer require capell-app/<package>
php artisan capell:package-cache:clear
php artisan capell:extension-install capell-app/<package> --dry-run
php artisan capell:extension-install capell-app/<package>
```

Composer presence, Capell installation/enabled state, and the selected runtime or
theme are separate states. Use the generic lifecycle command for packages both
with and without a custom install command: it invokes the package's declared
lifecycle when present. A package-specific setup command is an additional step
only when its README documents one. For example, the
[Inertia runtime guide](../../../docs/getting-started/inertia-runtime.md) installs
the bridge and an adapter before configuring the selected adapter; installing a
theme likewise does not apply it to a site.

For current package inventory and per-package descriptions, use generated extension pages and package-owned READMEs. The host [packages and extensions](../../../docs/packages/catalog.md) page only documents core package boundaries and authoring entry points. The remainder of this guide covers extension points you reach for when you're writing your own code.

### Long-running workers

Capell can run inside long-lived Laravel workers such as Octane. Package services that keep request-specific state in a singleton must implement `Capell\Core\Octane\Resettable` and be tagged with `Resettable::TAG`:

Treat a service lifetime as part of its public contract: registrations collected while providers boot may live for the worker lifetime, but anything derived from the current request or operation must be scoped. Use a reset-tagged singleton only when it genuinely has to preserve boot metadata as well as temporary operation state. Never register the same service as both scoped and resettable. Capell's test suite enforces an exact inventory of mutable singleton and static state; new mutable services must add an explicit classification and reason to that development-only guard.

```php
use Capell\Core\Octane\Resettable;

$this->app->singleton(ExampleRequestState::class);
$this->app->tag([ExampleRequestState::class], Resettable::TAG);
```

The service should clear only in-memory request state in `flushOctaneState()`. Do not clear persistent cache, database state, or package configuration from this hook. Scoped services are normally recreated by Laravel; use the reset hook for singletons that intentionally survive the request.

---

## Page Types And Component Registration

Blueprint subjects describe the models a Blueprint can target. Core registers the built-in `page`, `site`, and `theme` subjects in `BlueprintSubjectRegistry`.

Register an application-owned subject in your provider's `boot()` method, before the registry freezes on application boot. The model must implement `Capell\Core\Models\Contracts\Blueprintable`; an Eloquent model can use `HasBlueprint` with a `blueprint_id` relationship column.

```php
use App\Models\LandingExperience;
use Capell\Core\Data\BlueprintSubjectDescriptorData;
use Capell\Core\Support\BlueprintSubjectRegistry;

public function boot(): void
{
    $this->app->make(BlueprintSubjectRegistry::class)->register(new BlueprintSubjectDescriptorData(
        key: 'landing-experience',
        label: __('page_types.landing_experience'),
        modelClass: LandingExperience::class,
        ownerPackage: 'app/site',
    ));
}
```

Use your application's Composer name for `ownerPackage`. Optional packages pass the descriptor to `$this->surface()->blueprintSubject(...)` in `bootInstalledPackage()` and declare a `blueprint-subject` contribution in `capell.json`. That helper also populates the legacy page-type collection. `CapellCore::registerPageType(new PageTypeData(...))` alone only populates that legacy collection; it does not register a blueprint subject. Writing an unregistered string to `Blueprint.type` throws `UnknownBlueprintSubjectException`.

Use plain strings for labels when the data may cross Livewire boundaries. Closures can dehydrate badly in Livewire state; Core eagerly resolves built-in labels for this reason.

Component aliases are a separate registry. Use them when package code needs to refer to renderable component names by a stable type/key pair:

```php
use Capell\Core\Facades\CapellCore;

CapellCore::registerComponent('Page', 'LandingExperience', 'capell-example::pages.landing-experience');
```

Renderable definitions may also declare a `viewDataResolver`. Resolvers implement `RenderableViewDataResolver` and receive a `RenderableViewDataContext` containing the asset, translation, meta, dynamic data, and render key. Use this when a shared Blade dispatcher should only resolve a target and pass explicit, visitor-safe variables into the target view.

For frontend widgets, use `LayoutWidgetRegistry`; for admin content widgets, use `CapellAdmin::registerWidget()` or `registerDiscoverableWidgets()`.

## Model Interceptors

Model interceptors let packages change default install/setup data without replacing Core actions. They are used for creation flows where Capell owns the write but packages need to adjust data before persistence or react after creation.

Register an interceptor for the model, optional key/conditions, and interface:

```php
use Capell\Core\Contracts\ModelInterceptors\PageInterceptorInterface;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;

public function boot(): void
{
    CapellCore::registerModelInterceptor(
        Page::class,
        ExampleHomePageInterceptor::class,
        key: ['slug' => 'home'],
        priority: 20,
    );
}
```

The interceptor must implement the matching contract, such as `PageInterceptorInterface`, `BlueprintInterceptorInterface`, `LayoutInterceptorInterface`, or `ThemeInterceptorInterface`:

```php
use Capell\Core\Contracts\ModelInterceptors\PageInterceptorInterface;
use Capell\Core\Contracts\Pageable;

final class ExampleHomePageInterceptor implements PageInterceptorInterface
{
    public function beforeCreate(array $data): array
    {
        $data['meta']['example_package'] = true;

        return $data;
    }

    public function afterCreated(Pageable $page, array $data): void
    {
        // Dispatch package-owned setup work here if needed.
    }
}
```

Use interceptors for package defaults, not ordinary user writes. Runtime writes should go through Actions.

---

## Schema Hook Extenders

Schema hook extenders let you inject form fields into page or site edit form-builder at named positions, without overriding the entire schema.

### Available hooks

The `PageTranslationSchemaHookEnum` enum defines named injection points in the page translation editor:

| Hook                 | Position                        |
| -------------------- | ------------------------------- |
| `BeforeTitle`        | Before the title field          |
| `AfterTitle`         | After the title field           |
| `AfterContentEditor` | After the main content editor   |
| `AfterExtraContent`  | After the extra content section |
| `BeforeSearchMeta`   | Before the SEO/meta fields      |
| `AfterSearchMeta`    | After the SEO/meta fields       |

### Implementing a page schema extender

Create a class implementing `PageSchemaExtender` and tag it with `PageSchemaExtender::TAG`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\FormBuilder;

use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Override;

final class CustomPageSchemaExtender implements PageSchemaExtender
{
    #[Override]
    public function extendTranslationComponentsForHook(
        Schema $schema,
        PageTranslationSchemaHookEnum $hook
    ): array {
        return match ($hook) {
            PageTranslationSchemaHookEnum::AfterTitle => [
                TextInput::make('meta.custom.subtitle')
                    ->label(__('my-site.subtitle'))
                    ->maxLength(255),
            ],
            default => [],
        };
    }

    #[Override]
    public function extendSidebarComponents(Schema $schema): array
    {
        return [];
    }

    #[Override]
    public function extendRelationManagers(Model $record, array $relationManagers): array
    {
        return $relationManagers;
    }

    #[Override]
    public function extendTabs(Schema $schema, array $tabs): array
    {
        return $tabs;
    }
}
```

Define `subtitle` in your application's `lang/en/my-site.php`. The `meta.custom.subtitle` path above is inside the translations repeater, so it persists in **Translation.meta**. A top-level tab/sidebar using the same path writes **Page.meta**. A bare arbitrary input name does not add a persisted column. Scope the extender to the intended blueprint/site; the [complete walkthrough](../../../docs/getting-started/add-a-custom-page-field.md) demonstrates that check.

Use page sidebar components only for lightweight context that should sit beside the editor. Put larger editorial controls into translation hooks such as `AfterTitle` or into a full edit tab with `extendTabs()`.

Register the extender in your service provider:

```php
$this->app->tag([CustomPageSchemaExtender::class], PageSchemaExtender::TAG);
```

See [Schema Hooks reference](../../admin/docs/schemas/hooks.md) for full details including Site extenders.

---

## Event Registry (Callbacks & Subscribers)

The Admin package provides an event registry that lets packages and applications subscribe to lifecycle events in the admin panel.

### Registering a callback

```php
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Admin\Support\AdminEventHandlerInterface;
use Capell\Admin\Support\AdminEventRegistry;
use Livewire\Component;

final class RefreshPreviewHandler implements AdminEventHandlerInterface
{
    public function handle(array $payload, Component $component): void
    {
        if ($component instanceof EditPage) {
            $component->dispatch('refresh-preview');
        }
    }
}

resolve(AdminEventRegistry::class)->register(
    EditPage::class,
    'refreshPreview',
    RefreshPreviewHandler::class,
);
```

The callback only runs when the event fires from an instance of the specified class.

### Creating a subscriber

For more complex event handling, implement `EventSubscriber`:

```php
use Capell\Core\Facades\CapellCore;
use Capell\Core\Contracts\EventSubscriber;

class MyEventSubscriber implements EventSubscriber
{
    public function handle(string $event, object $context): void
    {
        if ($event === 'afterSave') {
            // Handle the event
        }
    }
}

// Register it
CapellCore::subscriberManager()->subscribe(MyEventSubscriber::class);

// Unregister when done
CapellCore::subscriberManager()->unsubscribe(MyEventSubscriber::class);
```

### Validation subscribers

For events that need to prevent an action from completing, implement `ValidationSubscriber`:

```php
use Capell\Admin\Contracts\ValidationSubscriber;
use Capell\Core\Models\Blueprint;

class TypeDeletionValidator implements ValidationSubscriber
{
    public function handle(string $event, object $context): void {}

    public function validate(string $event, object $context): bool
    {
        if ($event === 'validateCustomType' && $context instanceof Blueprint) {
            // Return false to prevent deletion
            return ! $this->hasRelatedRecords($context);
        }
        return true;
    }
}
```

### Available events

| Event                | Triggered by                                  |
| -------------------- | --------------------------------------------- |
| `afterSave`          | After a page is saved in `EditPage`           |
| `validateCustomType` | When validating whether a type can be deleted |

### Adding new events

To dispatch a custom event from your code:

```php
use Capell\Core\Support\Subscriber\SubscriberManager;

resolve(SubscriberManager::class)->notifySubscribers('myEvent', $context);
```

---

## Render Hooks

Render hooks let you inject HTML into named locations in frontend Blade components without overwriting any files.

See [Render Hooks](../../frontend/docs/extending-render-hooks.md) for the full guide.

**Quick example:** Add a badge after the title in asset tile components:

```php
use Capell\Frontend\Enums\RenderHookLocation;
use Capell\Frontend\Support\Render\RenderHookRegistry;

app(RenderHookRegistry::class)->register(
    RenderHookLocation::AfterTitle,
    function ($context) {
        return '<span class="badge">New</span>';
    },
    priority: 10,
    scenario: 'asset'
);
```

---

## Settings Schema Registry

Add your package's settings to the admin Settings page by registering a schema and settings class:

```php
use Capell\Core\Support\Settings\SettingsSchemaRegistry;

private function registerSettingsSchemas(): self
{
    $registry = resolve(SettingsSchemaRegistry::class);

    $registry->registerSettingsClass('my_package', MyPackageSettings::class);
    $registry->register('my_package', MyPackageSettingsSchema::class);

    return $this;
}
```

See [Settings Schema Registry](../../admin/docs/settings-schema-registry.md) for the full API reference, including how to extend, replace, or remove schemas from other packages.

---

## Extending core models

To substitute your own subclass for a core Capell model (e.g. `Page`, `Site`), bind the replacement in your package service provider's `register()` method:

```php
use Capell\Core\Models\Page;

public function register(): void
{
    $this->app->bind(Page::class, MyExtendedPage::class);
}
```

Capell code that resolves the model via `app(Page::class)` receives an instance of `MyExtendedPage`. This does not redirect static calls such as `Page::query()` or automatically add custom columns to revision, import or API contracts. Inspect the owning paths before relying on model replacement; the [custom-field guide](../../../docs/packages/custom-page-fields.md#advanced-model-customisation) explains the limits and alternatives.

> Previous versions used `CapellCore::registerModel(ModelEnum::Page, MyExtendedPage::class)`. That API has been removed. Container bindings are the replacement.

---

## Package Metadata And Discovery

Packages should describe themselves through `capell.json` and Composer metadata. Manifest registration is the source of truth for package name, kind, scopes, providers, commands, requirements, settings ownership, marketplace metadata, and contribution counts.

Provider-side `CapellCore::registerPackage(...)` remains for trusted first-party bootstrap and compatibility paths. New packages should prefer manifest metadata and keep providers focused on wiring concrete runtime registrations.

Capell extension API 1.1 adds the `content-widget` contribution type. Its class must implement `RegistersExtensionContentWidget`; Core treats it as public frontend output for capability, install-impact, and cache-safety auditing even when the manifest omits redundant frontend surface metadata. New packages may declare `capellApiVersion: ^1.1`, while existing `^1.0` packages remain compatible with the current 1.x API.

Register package models with `CapellCore::registerModels([...])` when they should appear in diagnostics, protected-table checks, morph maps, exports, or package metadata. This does not replace a Core model implementation; use a container binding for that.

If a package contribution is missing, start with:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan capell:package-cache:clear
php artisan list capell
```

Then check [Extension Troubleshooting](../../../docs/packages/extension-troubleshooting.md) for the runtime-specific path.

---

## Theme Chrome Components

Theme packages can register trusted public header and footer Blade components and logo views. Stored selectors must use these registrations, including when written outside admin forms.

```php
use Capell\Core\Support\Themes\ThemeChromeRegistry;

app(ThemeChromeRegistry::class)->registerHeader('vendor-theme::header', 'Vendor header');
app(ThemeChromeRegistry::class)->registerFooter('vendor-theme::footer', 'Vendor footer');
app(ThemeChromeRegistry::class)->registerLogo('vendor-theme::logo', 'Vendor logo');
```

Model saves validate `header_file` and `footer_file` against the registered options, and public rendering checks them again before selecting a view. Invalid stored layout or theme selectors return a generic 404. Site `logo_blade_view` metadata, including values inherited from Blueprint metadata, must also be registered; both public logo consumers omit unregistered logo views. The built-in `brand.capell-logo` is registered by default, and `false` disables the logo view. Load registrations for HTTP requests, CLI commands, jobs and imports.

## Related guides

### Core Documentation

- [README](../README.md)
- [Configuration Reference](../../../docs/development/configuration.md)
- [Frontend Guide](../../../docs/frontend/guide.md)
- [Packages and extensions](../../../docs/packages/catalog.md)
- [Local Development](../../../docs/development/local-development.md)

### Extension Point Guides

- [Static Site Extensions](static-site-extensions.md) — Hook into static site generation
- [Subscriber Manager](subscriber-manager.md) — Subscribe to fine-grained lifecycle events
- [Admin Tool Registry](../../admin/docs/admin-tool-registry.md) — Add custom toolbar items to the admin panel
- [Dashboard Widget Customization](../../admin/docs/dashboard-widget-customization.md) — Programmatically register dashboard Filament widgets
- [Settings Schema Registry](../../admin/docs/settings-schema-registry.md) — Add package settings to the admin Settings page
- [Render Hooks](../../frontend/docs/extending-render-hooks.md) — Inject HTML into frontend Blade components
- [Schema Hooks](../../admin/docs/schemas/hooks.md) — Add fields to edit form-builder at named positions
- [Tailwind Assets](../../frontend/docs/tailwind-assets.md) — Register Tailwind sources, imports, and plugins

### Performance & Optimization

- [Performance Index](../../../docs/performance/README.md) — Performance optimization overview
- [Cache Invalidation](../../../docs/performance/cache-invalidation.md) — Wire model changes to cache flushes
- [Critical Asset Optimization](../../../docs/performance/critical-asset-optimization.md) — Register critical/deferred frontend assets
- [Fragment Caching](../../../docs/performance/fragment-caching.md) — Cache expensive Blade fragments
- [ETag & Conditional Responses](../../../docs/performance/etag-and-conditional-responses.md) — Enable conditional responses via ETags
- [Lazy Page Hydration](../../../docs/performance/lazy-page-hydration.md) — Lazy-load JavaScript for non-critical routes

### Content Management

- [Content Management](content-management.md)
- [Page Management](page-management.md)

### Administration

- [Artisan Commands](../../../docs/development/artisan-commands.md)
