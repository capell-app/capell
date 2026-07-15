# Admin Bridges


Admin bridges are package-level integration classes for registering admin surface contributions in one place.

Use a bridge when a package needs to contribute several admin concerns together, such as settings schemas, pages, resources, widgets, panel extenders, user resource panels, relation managers, form lifecycle hooks, or table actions.

Do not replace low-level extension points with bridges. A bridge registers existing adapters; it does not replace `SettingsSchemaRegistry`, schema extenders, configurators, dashboard Filament widgets, panel extenders, or other focused extension contracts.

## Bridge Shape

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Bridges;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;

final class PackageAdminBridge implements AdminBridge
{
    public function isEnabled(AdminBridgeContextData $context): bool
    {
        return true;
    }

    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->page(PackageSettingsPage::class);
        $registrar->settingsSchema('package', PackageSettingsSchema::class);
        $registrar->welcomeTourStep(
            key: 'vendor-package.introduction',
            title: __('vendor-package::welcome.title'),
            description: __('vendor-package::welcome.description'),
            element: '.vendor-package-admin-panel',
            sort: 80,
        );
    }
}
```

Register the bridge from the package service provider:

```php
CapellAdmin::registerAdminBridge('vendor/package', PackageAdminBridge::class);
CapellAdmin::bootAdminBridges('vendor/package');
```

Packages that still support older admin versions should guard bridge registration and keep their previous direct registration as the fallback.

## User Resource Bridges

Use `UserResourceBridge` when a package contributes to the Capell user resource. Prefer extending `AbstractUserResourceBridge` so new bridge methods can be added safely in later Capell versions.

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Bridges;

use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Support\Bridges\AbstractUserResourceBridge;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class PackageUserResourceBridge extends AbstractUserResourceBridge
{
    public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
    {
        if ($context->record === null) {
            return [];
        }

        return [
            Section::make(__('package::admin.user_summary'))
                ->schema([]),
        ];
    }
}
```

Existing `UserSchemaExtender`, `UserFormExtender`, and `UserTableExtender` registrations continue to work. New package code should prefer bridge classes when the integration spans multiple admin concerns or should be discoverable from a single file.
