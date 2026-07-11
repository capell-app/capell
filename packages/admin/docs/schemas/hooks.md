# Schema Hook Extenders

![Capell Schema Hook Extenders screenshot](../images/screenshots/admin-dashboard.png)

Use schema extenders when a package needs to add fields, tabs, sidebar components, or relation managers to a first-party admin resource without copying the resource schema. Keep page editor sidebar components lightweight: the sidebar is reserved for quick context such as parent/page image and publish state. Larger editorial controls should use translation hooks or full edit tabs.

Prefer the abstract base classes when you only need one hook:

- `Capell\Admin\Support\Schemas\AbstractPageSchemaExtender`
- `Capell\Admin\Support\Schemas\AbstractSiteSchemaExtender`
- `Capell\Admin\Support\Schemas\AbstractUserSchemaExtender`

The base classes return empty components or the original array for hooks you do not override. If you implement an extender interface directly, you must implement every method on that interface.

## Registering Extenders

Tag extenders from the package admin provider:

```php
use Capell\Admin\Contracts\Extenders\PageSchemaExtender;

public function boot(): void
{
    $this->app->tag([ExamplePageSchemaExtender::class], PageSchemaExtender::TAG);
}
```

When using an admin bridge, register through the bridge registrar:

```php
$registrar->schemaExtender(ExamplePageSchemaExtender::class, PageSchemaExtender::TAG);
```

If the extender changes a cached admin surface, clear and rebuild the configurator cache:

```bash
php artisan capell:admin-clear-cache
php artisan capell:admin-cache-configurators
```

## Page Schema

`PageSchemaExtender::TAG` targets the page edit resource.

| Method                                                                                    | Use it for                                                   |
| ----------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| `extendTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook)` | Add fields around translated page title/content/meta fields. |
| `extendSidebarComponents(Schema $schema)`                                                 | Add lightweight sidebar context to the page editor.          |
| `extendTabs(Schema $schema, array $tabs)`                                                 | Add or modify top-level edit tabs for larger page settings.  |
| `extendRelationManagers(Model $record, array $relationManagers)`                          | Add relation managers for package-owned page relationships.  |

Translation hook values:

| Hook                 | Position                         |
| -------------------- | -------------------------------- |
| `BeforeTitle`        | Before the title field.          |
| `AfterTitle`         | After the title field.           |
| `AfterContentEditor` | After the main content editor.   |
| `AfterExtraContent`  | After the extra content section. |
| `BeforeSearchMeta`   | Before search/meta fields.       |
| `AfterSearchMeta`    | After search/meta fields.        |

Example:

```php
<?php

declare(strict_types=1);

namespace Vendor\Example\Admin;

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

## Site Schema

`SiteSchemaExtender::TAG` targets the site create/edit resource.

| Method                                                                                    | Use it for                                          |
| ----------------------------------------------------------------------------------------- | --------------------------------------------------- |
| `extendTranslationComponentsForHook(Schema $schema, PageTranslationSchemaHookEnum $hook)` | Add translated site fields around title/meta hooks. |
| `extendSiteMetaDetailsComponents(Schema $schema, array $components)`                      | Add or position non-translated site meta fields.    |
| `extendCreateWizardComponentsForHook(Schema $schema, SiteCreateWizardHookEnum $hook)`     | Add fields to the site creation wizard.             |
| `extendTabs(Schema $schema, array $tabs)`                                                 | Add or modify site edit tabs.                       |
| `extendRelationManagers(Model $record, array $relationManagers)`                          | Add relation managers for site-owned package data.  |

Current site create wizard hooks:

| Hook           | Position               |
| -------------- | ---------------------- |
| `PagesStepEnd` | End of the pages step. |

## Layout Schema

`LayoutSchemaExtender::TAG` targets layout edit pages.

| Method                                                           | Use it for                                           |
| ---------------------------------------------------------------- | ---------------------------------------------------- |
| `extendTabs(Schema $schema, array $tabs)`                        | Add or modify layout edit tabs.                      |
| `extendRelationManagers(Model $record, array $relationManagers)` | Add relation managers for layout-owned package data. |

## User Schema

`UserSchemaExtender::TAG` targets the Capell user resource. For new packages, prefer a `UserResourceBridge` when you need user form fields, sidebar panels, or relation managers because bridges can be enabled conditionally per package context.

| Method                                                                                              | Use it for                                                                |
| --------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| `supports(UserSchemaContextData $context)`                                                          | Limit the extender to specific user models or form contexts.              |
| `extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context)` | Add fields around identity, credentials, roles, profile, or footer hooks. |
| `extendSidebarComponents(Schema $schema, UserSchemaContextData $context)`                           | Add user edit sidebar components.                                         |
| `extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context)`    | Add user relation managers.                                               |

User hook values:

| Hook                                     | Position                           |
| ---------------------------------------- | ---------------------------------- |
| `BeforeIdentity` / `AfterIdentity`       | Around identity fields.            |
| `BeforeCredentials` / `AfterCredentials` | Around credential fields.          |
| `BeforeRoles` / `AfterRoles`             | Around role and permission fields. |
| `BeforeProfile` / `AfterProfile`         | Around profile fields.             |
| `Footer`                                 | End of the form.                   |

## Other Admin Extenders

Schema hooks are not the right extension point for every admin change.

| Need                                                            | Use                                                                                        |
| --------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Page preview action group                                       | `PagePreviewActionExtender`.                                                               |
| Page/site/resource header action                                | `PageHeaderActionExtender`, `SiteHeaderActionExtender`, or `ResourceHeaderActionExtender`. |
| Page title/slug field action or after-label schema              | `PageTitleWithSlugInputExtender`.                                                          |
| Page table columns, filters, bulk actions, or query changes     | `PageTableExtender`.                                                                       |
| User table columns, filters, record actions, or toolbar actions | `UserTableExtender`.                                                                       |
| Page edit form actions or header widgets                        | `PageEditExtender`.                                                                        |
| Publish panel HTML                                              | `PublishPanelExtender`.                                                                    |
| Import menu entries for list/manage pages                       | `ImportEntryRegistry::register(new ImportEntryData(...))`.                                 |
| Page/site export modal fields and options                       | `PageExportExtender`.                                                                      |
| Media edit header actions                                       | `MediaEditActionExtender`.                                                                 |
| Extensions page content                                         | `ExtensionsPageExtender` or `ExtensionsPageActionRegistry`.                                |
| Filament panel configuration                                    | `AdminPanelExtender`.                                                                      |

See [Admin extensions](../../../../docs/packages/admin-extensions.md) for the broader package authoring map.

## Debugging

If a schema contribution is missing:

1. Confirm the package admin provider is loaded.
2. Confirm the extender is tagged with the matching `::TAG` constant.
3. If registered through a bridge, confirm `CapellAdmin::bootAdminBridges($packageName)` runs for the same package name.
4. Run `php artisan optimize:clear`.
5. Run `php artisan capell:admin-clear-cache` and `php artisan capell:admin-cache-configurators`.
6. Check the exact target surface. Page, site, layout, user, title/slug, table, and header extenders use different tags.

Add a focused test for the resolver when you add an extender. The test should tag the extender, resolve the matching resolver, and assert that the expected component/action/relation manager is returned for the specific hook.
