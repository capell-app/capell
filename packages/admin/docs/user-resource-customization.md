# User Resource Customization

![Capell User Resource Customization screenshot](./images/screenshots/admin-dashboard.png)

Capell's user resource is the host surface for editing users in the Filament admin panel. Packages can extend it without replacing the resource by using two separate extension points:

- `UserSchemaExtender` adds form components, sidebar panels, and relation managers.
- `UserFormExtender` mutates submitted form data and runs after create/save.

Use `UserSchemaExtender` for UI. Use `UserFormExtender` only when custom fields need persistence handling.

## How the bridge works

User resource bridges are package-owned integrations that add package-specific detail to the user editor. The admin package provides the host surface and global switches; each package owns its own setting, extender, relation managers, queries, and translations.

The effective load rule is:

```php
Admin setting && package setting
```

Admin also exposes broader category switches for bridges that span package boundaries:

- `admin.enable_security_access_user_bridge`
- `admin.enable_content_ownership_user_bridge`
- `admin.enable_support_actions_user_bridge`

Use these when a bridge contributes security/access panels, content ownership and publishing activity, or support actions. Keep package-owned settings in place when the package needs its own local disable switch.

Category settings are intended to be combined with package settings:

```php
ShouldLoadUserResourceBridgeAction::run('enable_security_access_user_bridge', true)
    && ShouldLoadUserResourceBridgeAction::run(
        'enable_login_audit_user_bridge',
        resolve(LoginAuditSettings::class)->enable_user_resource_bridge,
    );
```

For example, Login Audit panels are shown only when both of these are enabled:

- `admin.enable_login_audit_user_bridge`
- `login_audit.enable_user_resource_bridge`

The same pattern is used by:

- `admin.enable_publishing_studio_user_bridge` and `publishing_studio.enable_user_resource_bridge`
- `admin.enable_agent_bridge_user_bridge` and `agent_bridge.enable_user_resource_bridge`

Use `Capell\Admin\Actions\Users\ShouldLoadUserResourceBridgeAction` inside bridge extenders to enforce this rule.

## Built-in bridges

### Login Audit

The Login Audit bridge adds **Security & Access** to user records:

- last login
- failed login count
- password last changed date where available
- email verified date
- MFA status where available
- active sessions where available
- audited session revocation and forced password reset actions where supported
- login audit history relation manager

### Publishing Studio

The Publishing Studio bridge adds **Content Ownership & Publishing Activity** to user records:

- pages, posts, media, blocks, drafts, and other package-owned content created or updated by the user
- recently published content
- recently unpublished content
- scheduled content
- reverted content
- publishing history relation managers scoped to the edited user

Prefer CMS-responsibility activity over generic audit trails so support staff can answer "what did this editor touch?" quickly.

### Support Actions

Support action bridges add audited account-support controls to user records:

- Act as owner, only when the auth model and permissions allow it
- resend invite
- verify email
- deactivate or reactivate user
- unlock account
- force password reset

Every support action must be permission-gated and audited by the package that owns the action.

Capell's built-in Act as owner control is backed by the `impersonate_users` permission and the host `User` model's `HasImpersonation` trait. Starting and stopping the session writes `support` activity log entries with the support user as the causer and the owner account as the subject, so account access remains visible in the normal Activity Log.

### Agent Bridge

The Agent Bridge bridge adds **AI / agent activity** to user records:

- tokens owned by the user
- confirmations requested or approved
- audit entries and capability usage
- token status, last-used time, and expiry

Agent Bridge panels must never expose raw token values. Show metadata only.

## Schema context

Every user schema extender receives `UserSchemaContextData`:

```php
use Capell\Admin\Data\Schemas\UserSchemaContextData;

$context->operation;     // create or edit
$context->record;        // edited model on edit, null on create
$context->roleNames;     // role names resolved from the edited user
$context->schemaType;    // role-derived schema type
$context->resourceName;  // users
```

Use helpers when targeting roles or schema types:

```php
if ($context->hasRole('editor') && $context->isSchemaType('editorial')) {
    // Add editorial user controls.
}
```

Role-to-schema-type mapping is configured in `capell-admin.php`:

```php
'user_resource' => [
    'default_schema_type' => 'default',
    'role_schema_types' => [
        'super_admin' => 'administrator',
        'editor' => 'editorial',
    ],
],
```

The first configured matching role wins. When nothing matches, Capell uses `default_schema_type`.

## Form hook points

`UserSchemaExtender::extendComponentsForHook()` receives one of these hooks:

- `BeforeIdentity`
- `AfterIdentity`
- `BeforeCredentials`
- `AfterCredentials`
- `BeforeRoles`
- `AfterRoles`
- `BeforeProfile`
- `AfterProfile`
- `Footer`

Add fields near the data they belong to. Avoid using the footer for package dashboards or record history; use sidebar panels or relation managers for that.

## Creating a schema extender

Extend `AbstractUserSchemaExtender` so new methods added later do not break your package.

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Extenders;

use Capell\Admin\Actions\Users\ShouldLoadUserResourceBridgeAction;
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Capell\Admin\Enums\UserSchemaHookEnum;
use Capell\Admin\Support\Schemas\AbstractUserSchemaExtender;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Vendor\Package\Settings\PackageSettings;

final class PackageUserSchemaExtender extends AbstractUserSchemaExtender
{
    public function supports(UserSchemaContextData $context): bool
    {
        return ShouldLoadUserResourceBridgeAction::run(
            'enable_example_user_bridge',
            resolve(PackageSettings::class)->enable_user_resource_bridge,
        );
    }

    public function extendComponentsForHook(Schema $schema, UserSchemaHookEnum $hook, UserSchemaContextData $context): array
    {
        if ($hook !== UserSchemaHookEnum::AfterProfile) {
            return [];
        }

        return [
            TextInput::make('external_reference')
                ->label(__('vendor-package::form.external_reference'))
                ->maxLength(100),
        ];
    }
}
```

Register the extender from your admin provider:

```php
use Capell\Admin\Contracts\Extenders\UserSchemaExtender;
use Vendor\Package\Extenders\PackageUserSchemaExtender;

$this->app->bind(PackageUserSchemaExtender::class);
$this->app->tag([PackageUserSchemaExtender::class], UserSchemaExtender::TAG);
```

## Persisting custom fields

Schema extenders add UI only. If a field does not map directly to a column or relationship on the configured user model, pair it with `UserFormExtender`.

```php
<?php

declare(strict_types=1);

namespace Vendor\Package\Extenders;

use Capell\Admin\Contracts\Extenders\UserFormExtender;
use Illuminate\Database\Eloquent\Model;

final class PackageUserFormExtender implements UserFormExtender
{
    public function mutateDataBeforeCreate(array $data): array
    {
        return $this->moveExternalReference($data);
    }

    public function afterCreate(Model $record): void {}

    public function mutateDataBeforeSave(Model $record, array $data): array
    {
        return $this->moveExternalReference($data);
    }

    public function afterSave(Model $record): void {}

    private function moveExternalReference(array $data): array
    {
        $data['bio'] = $data['external_reference'] ?? $data['bio'] ?? null;
        unset($data['external_reference']);

        return $data;
    }
}
```

Register it separately:

```php
use Capell\Admin\Contracts\Extenders\UserFormExtender;
use Vendor\Package\Extenders\PackageUserFormExtender;

$this->app->bind(PackageUserFormExtender::class);
$this->app->tag([PackageUserFormExtender::class], UserFormExtender::TAG);
```

## Sidebar panels

Use sidebar components for compact summaries. Keep row-level history in relation managers.

```php
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;

public function extendSidebarComponents(Schema $schema, UserSchemaContextData $context): array
{
    if ($context->record === null) {
        return [];
    }

    return [
        Section::make(__('vendor-package::user.summary'))
            ->compact()
            ->schema([
                Text::make(fn (): string => __('vendor-package::user.summary_line')),
            ]),
    ];
}
```

Do not put secrets, model IDs that users do not need, signed URLs, or raw package internals into sidebar panels.

## Relation managers

Use relation managers for package-owned history and activity tables. `EditUser` resolves user bridge relation managers through Filament's normal relation-manager filtering path, so `canViewForRecord()` still applies.

```php
use Capell\Admin\Data\Schemas\UserSchemaContextData;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;

public function extendRelationManagers(Model $record, array $relationManagers, UserSchemaContextData $context): array
{
    return [
        ...$relationManagers,
        PackageActivityRelationManager::class,
    ];
}

final class PackageActivityRelationManager extends RelationManager
{
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewPackageActivity', $ownerRecord) ?? false;
    }
}
```

Every relation manager must scope its query to the edited user. Add tests proving unrelated users' records are excluded.

## Package settings

Each bridge package should expose its own setting:

```php
public bool $enable_user_resource_bridge = true;
```

Register the settings class and schema through `SettingsSchemaRegistry`, and add a guarded settings migration:

```php
if (! $this->migrator->exists('example.enable_user_resource_bridge')) {
    $this->migrator->add('example.enable_user_resource_bridge', true);
}
```

The package setting lets package owners disable only their bridge while leaving other user bridges available. The Admin setting lets site owners disable a whole bridge category globally.

## Admin language preference

The user resource includes an Admin Language field when the Capell migration has added `users.preferred_admin_language_id`.

Options are loaded from enabled Capell `Language` records. The selected language is applied only inside authenticated Capell/Filament admin requests. If the selected language is disabled, deleted, missing, or has an invalid locale value, Capell falls back to `config('app.locale')`.

The preference is persisted by Capell's user form save flow, so host user models do not need to include `preferred_admin_language_id` in `$fillable`.

## Testing checklist

For each user bridge, add focused tests that prove:

- the bridge loads only when Admin and package settings are both enabled
- sidebar components render on the edit-user form
- relation managers are present only when allowed by `canViewForRecord()`
- relation manager queries are scoped to the edited user
- custom form fields persist through `UserFormExtender` when they are not native user columns
- sensitive values, such as raw Agent Bridge tokens, are not rendered
