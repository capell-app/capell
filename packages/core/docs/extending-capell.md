# Extending Capell

![Capell Extending Capell screenshot](./images/screenshots/core-page-structure.png)

> **Who is this for?**
> Developers who want to extend Capell with page types, model interceptors, event subscribers, settings, frontend hooks, or add-on packages.

Capell is designed to be extended without modifying its core source code. This document explains the main extension points available to your application and to package authors.

---

## Table of Contents

1. [Extension Discovery](#1-extension-discovery)
2. [Page Types And Component Registration](#2-page-types-and-component-registration)
3. [Model Interceptors](#3-model-interceptors)
4. [Schema Hook Extenders](#4-schema-hook-extenders)
5. [Event Registry (Callbacks & Subscribers)](#5-event-registry-callbacks--subscribers)
6. [Render Hooks](#6-render-hooks)
7. [Settings Schema Registry](#7-settings-schema-registry)
8. [Extending core models](#8-extending-core-models)
9. [Package Metadata And Discovery](#9-package-metadata-and-discovery)
10. [Further Reading](#10-further-reading)

---

## Which extension point do I use?

Capell offers several extension mechanisms. Pick by what you're extending:

| I want to…                                                         | Use                                                        | Section                                                                                       |
| ------------------------------------------------------------------ | ---------------------------------------------------------- | --------------------------------------------------------------------------------------------- |
| Add a page subject type                                            | `CapellCore::registerPageType(new PageTypeData(...))`      | [§2](#2-page-types-and-component-registration)                                                |
| Register component aliases                                         | `CapellCore::registerComponent()` / `registerComponents()` | [§2](#2-page-types-and-component-registration)                                                |
| Change seed/install data for pages, layouts, themes, or blueprints | `CapellCore::registerModelInterceptor()`                   | [§3](#3-model-interceptors)                                                                   |
| Add fields to an existing page or site edit form                   | Schema hook extender                                       | [§4](#4-schema-hook-extenders)                                                                |
| React to an admin lifecycle event (e.g. after save)                | Event registry callback / subscriber                       | [§5](#5-event-registry-callbacks--subscribers)                                                |
| Block an action based on a condition                               | `ValidationSubscriber`                                     | [§5](#5-event-registry-callbacks--subscribers)                                                |
| Subscribe to fine-grained lifecycle events                         | `SubscriberManager::subscribe()`                           | [Subscriber Manager](subscriber-manager.md)                                                   |
| Hook into static site export                                       | `StaticSiteExtensionRegistry::register()`                  | [Static Site Extensions](static-site-extensions.md)                                           |
| Inject HTML into a frontend Blade component                        | Render hook                                                | [§6](#6-render-hooks)                                                                         |
| Register selectable theme header/footer components                 | `ThemeChromeRegistry::register*()`                         | [Theme Chrome Components](#theme-chrome-components)                                           |
| Add a custom admin toolbar item                                    | Tag with `AdminToolItem::TAG`                              | [Admin Tool Registry](../../admin/docs/admin-tool-registry.md)                                |
| Add a dashboard Filament widget programmatically                   | `CapellAdmin::registerDashboardFilamentWidget()`           | [Dashboard Widget Customization](../../admin/docs/dashboard-widget-customization.md)          |
| Add a tab to the admin Settings page                               | Settings Schema Registry                                   | [§7](#7-settings-schema-registry)                                                             |
| Wire model changes to cache flushes                                | `CacheInvalidationRegistry::registerDependency()`          | [Cache Invalidation](../../../docs/performance/cache-invalidation.md)                         |
| Register runtime frontend assets                                   | `FrontendResourceRegistry` / `FrontendAssetContributor`    | [Frontend Asset Optimization](../../../docs/performance/critical-asset-optimization.md)       |
| Cache an expensive Blade fragment                                  | `@cache(...) ... @endcache` directive                      | [Fragment Caching](../../../docs/performance/fragment-caching.md)                             |
| Enable conditional responses via ETags                             | `ETagMiddleware` + header handling                         | [ETag & Conditional Responses](../../../docs/performance/etag-and-conditional-responses.md)   |
| Lazy-load JavaScript for non-critical routes                       | Lazy page hydration                                        | [Lazy Page Hydration](../../../docs/performance/lazy-page-hydration.md)                       |
| Register Tailwind sources, imports, or plugins                     | `TailwindAssetsRegistry::register*()`                      | [Tailwind Assets](../../frontend/docs/tailwind-assets.md)                                     |
| Load vendor build assets only for matching pages                   | `VendorAssetConditionRegistry::register()`                 | [Conditional Vendor Assets](../../frontend/docs/tailwind-assets.md#conditional-vendor-assets) |
| Flush singleton state between Octane requests                      | Implement `Resettable` and tag with `Resettable::TAG`      | [Long-running workers](#long-running-workers)                                                 |
| Substitute my own subclass for a core model                        | Container binding                                          | [§8](#8-extending-core-models)                                                                |
| Register package metadata                                          | `capell.json` plus manifest registration                   | [§9](#9-package-metadata-and-discovery)                                                       |

**Rule of thumb:** prefer targeted extension points over publishing or copying host package files. Published files stop receiving upstream fixes.

---

## 1. Extension Discovery

The simplest way to add optional product capability is to install a package that already owns that feature. Extension packages follow the normal Composer install pattern:

```bash
composer require capell-app/<package>
php artisan capell:<package>-install
```

For current package inventory and per-package descriptions, use generated extension pages and package-owned READMEs. The host [packages and extensions](../../../docs/packages/catalog.md) page only documents core package boundaries and authoring entry points. The remainder of this guide covers extension points you reach for when you're writing your own code.

### Long-running workers

Capell can run inside long-lived Laravel workers such as Octane. Package services that keep request-specific state in a singleton must implement `Capell\Core\Octane\Resettable` and be tagged with `Resettable::TAG`:

```php
use Capell\Core\Octane\Resettable;

$this->app->singleton(ExampleRequestState::class);
$this->app->tag([ExampleRequestState::class], Resettable::TAG);
```

The service should clear only in-memory request state in `flushOctaneState()`. Do not clear persistent cache, database state, or package configuration from this hook. Scoped services are normally recreated by Laravel; use the reset hook for singletons that intentionally survive the request.

---

## 2. Page Types And Component Registration

Page types describe the model subject a Blueprint can target. Core registers the built-in `page`, `site`, and `theme` subjects from `BlueprintSubjectEnum`.

Register a page type from a provider when a package owns a new public content subject:

```php
use Capell\Core\Data\PageTypeData;
use Capell\Core\Facades\CapellCore;
use Vendor\Example\Models\LandingExperience;

public function boot(): void
{
    CapellCore::registerPageType(new PageTypeData(
        name: 'landing-experience',
        model: LandingExperience::class,
        label: __('capell-example::page_types.landing_experience'),
    ));
}
```

Use plain strings for labels when the data may cross Livewire boundaries. Closures can dehydrate badly in Livewire state; Core eagerly resolves built-in labels for this reason.

Component aliases are a separate registry. Use them when package code needs to refer to renderable component names by a stable type/key pair:

```php
CapellCore::registerComponent('Page', 'LandingExperience', 'capell-example::pages.landing-experience');
```

Renderable definitions may also declare a `viewDataResolver`. Resolvers implement `RenderableViewDataResolver` and receive a `RenderableViewDataContext` containing the asset, translation, meta, dynamic data, and render key. Use this when a shared Blade dispatcher should only resolve a target and pass explicit, visitor-safe variables into the target view.

For frontend widgets, use `LayoutWidgetRegistry`; for admin content widgets, use `CapellAdmin::registerWidget()` or `registerDiscoverableWidgets()`.

## 3. Model Interceptors

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

## 4. Schema Hook Extenders

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

namespace App\Filament\FormBuilder;

use Capell\Admin\Contracts\Extenders\PageSchemaExtender;
use Capell\Admin\Enums\PageTranslationSchemaHookEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class CustomPageSchemaExtender implements PageSchemaExtender
{
    public function extendTranslationComponentsForHook(
        Schema $schema,
        PageTranslationSchemaHookEnum $hook
    ): array {
        return match ($hook) {
            PageTranslationSchemaHookEnum::AfterTitle => [
                TextInput::make('subtitle')
                    ->label('Subtitle')
                    ->maxLength(255),
            ],
            default => [],
        };
    }

    public function extendSidebarComponents(Schema $schema): array
    {
        return [];
    }

    public function extendRelationManagers(Model $record, array $relationManagers): array
    {
        return $relationManagers;
    }

    public function extendTabs(Schema $schema, array $tabs): array
    {
        return $tabs;
    }
}
```

Use page sidebar components only for lightweight context that should sit beside the editor. Put larger editorial controls into translation hooks such as `AfterTitle` or into a full edit tab with `extendTabs()`.

Register the extender in your service provider:

```php
$this->app->tag([CustomPageSchemaExtender::class], PageSchemaExtender::TAG);
```

See [Schema Hooks reference](../../admin/docs/schemas/hooks.md) for full details including Site extenders.

---

## 5. Event Registry (Callbacks & Subscribers)

The Admin package provides an event registry that lets packages and applications subscribe to lifecycle events in the admin panel.

### Registering a callback

```php
use Capell\Admin\Filament\Resources\Pages\EditPage;
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

## 6. Render Hooks

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

## 7. Settings Schema Registry

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

## 8. Extending core models

To substitute your own subclass for a core Capell model (e.g. `Page`, `Site`), bind the replacement in your package service provider's `register()` method:

```php
use Capell\Core\Models\Page;

public function register(): void
{
    $this->app->bind(Page::class, MyExtendedPage::class);
}
```

Any Capell code that resolves the model via `app(Page::class)` will receive an instance of `MyExtendedPage`. This includes Filament resources, loaders, and actions that explicitly resolve through the container.

> Previous versions used `CapellCore::registerModel(ModelEnum::Page, MyExtendedPage::class)`. That API has been removed. Container bindings are the replacement.

---

## 9. Package Metadata And Discovery

Packages should describe themselves through `capell.json` and Composer metadata. Manifest registration is the source of truth for package name, kind, scopes, providers, commands, requirements, settings ownership, marketplace metadata, and contribution counts.

Provider-side `CapellCore::registerPackage(...)` remains for trusted first-party bootstrap and compatibility paths. New packages should prefer manifest metadata and keep providers focused on wiring concrete runtime registrations.

Capell extension API 4.1 adds the `content-widget` contribution type. Its class must implement `RegistersExtensionContentWidget`; Core treats it as public frontend output for capability, install-impact, and cache-safety auditing even when the manifest omits redundant frontend surface metadata. New packages may declare `capellApiVersion: ^4.1`, while existing `^4.0` packages remain compatible with the current 4.x API.

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

Theme packages can register public header and footer Blade components for admin selection without hard-coding free-text component names into theme forms.

```php
use Capell\Core\Support\Themes\ThemeChromeRegistry;

app(ThemeChromeRegistry::class)->registerHeader('vendor-theme::header', 'Vendor header');
app(ThemeChromeRegistry::class)->registerFooter('vendor-theme::footer', 'Vendor footer');
```

The admin theme form validates `header_file` and `footer_file` against the registered options when saving. Public frontend rendering still reads the saved component name directly, so registration affects admin validation and editing, not normal page boot.

## 10. Further Reading

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
