# Recovery Center

![Capell recovery imports](../images/generated/admin/recovery-imports.png)

The Recovery Center is the admin shell for import and recovery work. Admin provides the navigation, permissions, import-session resource, notifications, and fallback actions. The actual export/import/rollback implementation is supplied by the optional `capell-app/migration-assistant` package.

This split matters: export/import is not a Core feature. If Migration Assistant is not installed, Admin can show the recovery area but import actions either stay unavailable or report that the Migration Assistant package is required.

Content list pages expose a consistent top-level **Import** menu when import/export is enabled in admin settings. Admin owns that visible action surface and the registration contract; package-owned import workflows register concrete entries into `Capell\Admin\Support\ImportEntryRegistry`. If a content page has no registered importer, the menu shows a Migration Assistant fallback instead of running any domain import logic in Admin.

## What Admin Provides

| Admin surface       | Purpose                                                                                         |
| ------------------- | ----------------------------------------------------------------------------------------------- |
| Import sessions     | Review previous import attempts, current status, timestamps, counts, and errors.                |
| Recovery navigation | Groups import/recovery screens in the admin panel.                                              |
| Notifications       | Sends completion and failure messages for import sessions.                                      |
| Migration contracts | Defines interfaces such as page export so Migration Assistant can bind the real implementation. |
| Permissions         | Installs and checks recovery/import permissions for admin users.                                |

## What Migration Assistant Provides

`capell-app/migration-assistant` owns the actual recovery workflows:

| Workflow         | What it does                                                                                                  |
| ---------------- | ------------------------------------------------------------------------------------------------------------- |
| Page export      | Packages selected pages and relations for transfer or safekeeping.                                            |
| Site export      | Packages a site and related content.                                                                          |
| Package import   | Reads package manifests, validates integrity, resolves relations, ingests media, and creates import sessions. |
| Rollback report  | Records rollback evidence and manual recovery instructions for completed imports.                             |
| WordPress import | Reads WordPress XML and runs it through a reviewable import path.                                             |

Migration Assistant lives in the sibling first-party packages repository. See [Approved packages](../packages/catalog.md#capell-operations) for where it fits in the product.

## Editor Workflow

1. Open the Recovery Center from the admin navigation.
2. Start an import using the package-specific screen made available by Migration Assistant.
3. Review validation results, URL collisions, and relation resolution prompts.
4. Confirm the import plan.
5. Watch the import session until it completes or fails.
6. Review imported draft content before publishing it.

When PublishingStudio is installed, imported content can be reviewed before it reaches the live site. That keeps recovery work out of the public frontend until an editor or approver signs it off.

## Developer Notes

Admin references Migration Assistant through contracts and null implementations. Do not put import/export domain logic in Admin resources or Filament pages. Add package behavior in Migration Assistant, bind the relevant contracts, and expose only the required admin screens through package registration.

Packages add menu entries by registering `Capell\Admin\Data\ImportEntryData` during boot:

```php
use Capell\Admin\Data\ImportEntryData;
use Capell\Admin\Support\ImportEntryRegistry;
use Filament\Actions\Action;

app(ImportEntryRegistry::class)->register(new ImportEntryData(
    key: 'vendor.package.import-pages',
    labelKey: 'vendor-package::imports.pages',
    descriptionKey: 'vendor-package::imports.pages_description',
    icon: 'heroicon-o-document-arrow-up',
    sort: 10,
    pageClasses: [\Capell\Admin\Filament\Resources\Pages\Pages\ListPages::class],
    actionFactory: fn (): Action => Action::make('importVendorPages')
        ->label(__('vendor-package::imports.pages'))
        ->url(route('filament.admin.pages.vendor-import-pages')),
    authorize: fn (): bool => auth()->user()?->can('import pages') === true,
));
```

Keep heavy archive parsing, media ingestion, package import, rollback, and validation work in Migration Assistant or the package that owns the workflow. The Admin entry should only point to the package-owned action, page, URL, or wizard.

Related docs:

- [Approved packages](../packages/catalog.md)
- [Package admin extensions](../packages/admin-extensions.md)
- [Actions, Data, and settings](../packages/data-actions-settings.md)
