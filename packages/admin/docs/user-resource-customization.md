# User resource customization

Capell exposes one extension point for the admin user resource: `UserResourceBridge`.
A bridge can contribute form fields, sidebar sections, relation managers, lifecycle
mutations, table columns, filters, and actions without splitting one package feature
across separate extender contracts.

Extend `AbstractUserResourceBridge` so a package only implements the methods it needs.

## Register a bridge

Register bridges through the package's `AdminBridge`:

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Admin;

use Capell\Admin\Contracts\Bridges\AdminBridge;
use Capell\Admin\Data\Bridges\AdminBridgeContextData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Vendor\Package\Admin\Bridges\PackageUserResourceBridge;

final class PackageAdminBridge implements AdminBridge
{
    public function register(AdminBridgeRegistrar $registrar, AdminBridgeContextData $context): void
    {
        $registrar->userResourceBridge(PackageUserResourceBridge::class);
    }
}
```

The registrar scopes the bridge and tags it with `UserResourceBridge::TAG`. A scoped
bridge may safely keep request-local state between its form lifecycle methods.

## Control when a bridge loads

`supports()` receives `UserSchemaContextData`, including the create/edit mode, user
model, role names, schema type, and resource name:

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Admin\Bridges;

use Capell\Admin\Actions\Bridges\ShouldLoadAdminBridgeAction;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Support\Bridges\AbstractUserResourceBridge;

final class PackageUserResourceBridge extends AbstractUserResourceBridge
{
    public function supports(UserSchemaContextData $context): bool
    {
        return $context->isSchemaType('security')
            && ShouldLoadAdminBridgeAction::run(
                adminSetting: 'enable_security_access_user_bridge',
                packageEnabled: true,
                packageName: 'vendor/package',
            );
    }
}
```

Use `ShouldLoadAdminBridgeAction` when availability depends on an admin setting and an
installed package. Use the context directly for page mode, roles, or schema type.

## Add form components

Components are placed through `UserSchemaHookEnum`:

```php
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

public function extendComponentsForHook(
    Schema $schema,
    UserSchemaHookEnum $hook,
    UserSchemaContextData $context,
): array {
    if ($hook !== UserSchemaHookEnum::AfterProfile) {
        return [];
    }

    return [TextInput::make('external_reference')];
}
```

Available hooks are defined by `UserSchemaHookEnum`. Return only the components for the
requested hook.

Sidebar components use `extendSidebarComponents()`. Relation managers use
`extendRelationManagers()` and receive the current record, existing managers, and the
same context.

## Persist custom form data

Bridge lifecycle methods run in registration order:

```php
use Illuminate\Database\Eloquent\Model;

public function mutateDataBeforeCreate(array $data): array
{
    $data['bio'] = $data['external_reference'] ?? null;
    unset($data['external_reference']);

    return $data;
}

public function mutateDataBeforeSave(Model $record, array $data): array
{
    $data['bio'] = $data['external_reference'] ?? null;
    unset($data['external_reference']);

    return $data;
}
```

Use `afterCreate()` and `afterSave()` for writes that require the persisted user model.
Keep domain writes in package Actions and call those Actions from the bridge.

## Extend the users table

The same bridge may return Filament table contributions:

```php
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;

public function columns(): array
{
    return [TextColumn::make('external_reference')];
}

public function filters(): array
{
    return [Filter::make('has_external_reference')];
}

public function recordActions(): array
{
    return [Action::make('refresh_external_reference')];
}

public function toolbarActions(): array
{
    return [];
}
```

## Test the bridge

Test the bridge directly for context filtering and lifecycle mutations. Add a focused
Livewire test when the package contributes visible form or table behavior:

```php
app()->bind(PackageUserResourceBridge::class);
app()->tag([PackageUserResourceBridge::class], UserResourceBridge::TAG);

Livewire::test(EditUser::class, ['record' => $user->getKey()])
    ->assertFormFieldExists('external_reference')
    ->fillForm(['external_reference' => 'EXT-123'])
    ->call('save')
    ->assertHasNoFormErrors();
```

Test unsupported contexts as well, especially role- or schema-specific bridges.
