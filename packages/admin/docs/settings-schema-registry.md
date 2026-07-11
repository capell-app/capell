# Settings Schema Registry

![Capell Settings Schema Registry screenshot](./images/screenshots/admin-dashboard.png)

The Settings Schema Registry is a runtime registry of settings form-builder. The admin **Settings** page renders first-party Capell settings groups (`core`, `admin`, and `frontend`) as tabs when they are registered. Marketplace and third-party package settings should be exposed through explicit extension management modal surfaces that reuse the same registry.

## Architecture

### Core Components

1. **SettingsSchemaRegistry** (`Capell\Core\Support\Settings\SettingsSchemaRegistry`)
    - Central registry maintaining all settings schemas
    - Organizes schemas by group (e.g., 'core', 'admin', 'frontend')
    - Supports multiple schemas per group (composable)
    - Stores optional metadata for package-owned settings surfaces

2. **SettingsSchemaBootstrapper** (`Capell\Core\Support\Settings\SettingsSchemaBootstrapper`)
    - Manages extension callbacks
    - Executes after package registration
    - Allows dynamic schema modifications

3. **HasSchema Contract** (`Capell\Admin\Filament\Contracts\HasSchema`)
    - Interface all settings schemas must implement
    - Defines `make(Schema $schema): array` method

## Basic Usage

### Registering a Settings Schema

In your package's service provider:

```php
use Capell\YourPackage\Filament\Settings\YourSettingsSchema;
use Capell\YourPackage\Settings\YourSettings;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Filament\Support\Icons\Heroicon;

private function registerSettingsSchemas(): self
{
    $registry = resolve(SettingsSchemaRegistry::class);

    // Register the settings class (for form hydration/saving)
    $registry->registerSettingsClass('yourgroup', YourSettings::class);

    $registry->registerMetadata(new SettingsGroupMetadata(
        group: 'yourgroup',
        label: 'your-package::settings.title',
        icon: Heroicon::OutlinedCog6Tooth,
        navigationGroup: 'capell-admin::navigation.group_system',
        packageName: 'capell-app/your-package',
    ));

    // Register the schema class (for form building)
    $registry->register('yourgroup', YourSettingsSchema::class);

    return $this;
}
```

### Creating a Package Settings Modal

Package settings are exposed from the Extensions page as an explicit opt-in modal surface. Register the settings class, schema, metadata, then register the management surface:

```php
<?php

declare(strict_types=1);

use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;

$registry = resolve(SettingsSchemaRegistry::class);
$registry->registerSettingsClass('your_package', YourPackageSettings::class);
$registry->registerMetadata(new SettingsGroupMetadata(
    group: 'your_package',
    label: 'Your package settings',
    packageName: 'capell-app/your-package',
));
$registry->register('your_package', YourPackageSettingsSchema::class);

CapellAdmin::registerExtensionManagementSurface(ExtensionManagementSurfaceData::settings(
    packageName: 'capell-app/your-package',
    label: 'Your package settings',
    settingsGroup: 'your_package',
));
```

Do not register a package settings page unless the package needs a full custom admin tool. The registry stores settings classes and schemas only; it does not automatically create extension management UI.

### Creating a Settings Schema

All settings schemas must implement the `HasSchema` contract:

```php
<?php

declare(strict_types=1);

namespace Capell\YourPackage\Filament\Settings;

use Capell\Admin\Filament\Contracts\HasSchema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Checkbox;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class YourSettingsSchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            Section::make(__('your-package::settings.general'))
                ->columnSpanFull()
                ->schema([
                    TextInput::make('api_key')
                        ->label(__('your-package::settings.api_key'))
                        ->required(),

                    Checkbox::make('enabled')
                        ->label(__('your-package::settings.enabled'))
                        ->default(true),
                ])
                ->columns(2),
        ];
    }
}
```

### Field Presentation Guidelines

Settings schemas should return top-level `Section` components, not bare fields or bare `Grid` components. A section gives labels, helper text, toggles, and inputs the Filament card background they need in both light and dark mode. Never leave labels and inputs floating directly on the page background, and do not call `contained(false)` on a section that contains normal form fields unless the section is immediately wrapped by another contained panel.

Use `Section::make()->columns()` for simple responsive layout inside the panel. Use a nested `Grid` only when it makes the schema clearer, and keep that grid inside a contained section.

Good:

```php
Section::make(__('your-package::settings.display'))
    ->columnSpanFull()
    ->schema([
        TextInput::make('items_per_page')
            ->label(__('your-package::settings.items_per_page'))
            ->numeric(),
    ])
    ->columns(2);
```

Avoid:

```php
Grid::make(2)
    ->schema([
        TextInput::make('items_per_page'),
    ]);

Section::make(__('your-package::settings.display'))
    ->contained(false)
    ->schema([
        TextInput::make('items_per_page'),
    ]);
```

### Creating a Settings Class

Your settings class should extend `Spatie\LaravelSettings\Settings`:

```php
<?php

declare(strict_types=1);

namespace Capell\YourPackage\Settings;

use Capell\Core\Contracts\SettingsContract;
use Capell\YourPackage\Filament\Settings\YourSettingsSchema;
use Spatie\LaravelSettings\Settings;

class YourSettings extends Settings implements SettingsContract
{
    public string $api_key;
    public bool $enabled;

    public static function group(): string
    {
        return 'yourgroup';
    }

    public static function schema(): string
    {
        return YourSettingsSchema::class;
    }
}
```

## Advanced Usage

### Multiple Schemas Per Group (Composition)

You can register multiple schemas for the same group. They will be merged together:

```php
$registry = resolve(SettingsSchemaRegistry::class);

// Core schema
$registry->register('admin', AdminCoreSchema::class, 'core');

// Additional schema from a package
$registry->register('admin', AdminExtendedSchema::class, 'extended');
```

Both schemas will appear on the package-owned settings modal for that group.

### Replacing an Existing Schema

To override a schema registered by another package:

```php
$registry = resolve(SettingsSchemaRegistry::class);

// Replace the core admin schema with a custom one
$registry->replace('admin', CustomAdminSchema::class, 'AdminSettingsSchema');
```

### Removing a Schema

To remove a schema entirely:

```php
$registry = resolve(SettingsSchemaRegistry::class);

// Remove a specific schema
$registry->remove('admin', 'AdminSettingsSchema');

// Remove all schemas from a group
$registry->removeGroup('admin');
```

### Dynamic Schema Registration

Use the bootstrapper to register schemas after all packages have loaded:

```php
use Capell\Core\Support\Settings\SettingsSchemaBootstrapper;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;

// In your service provider's boot method
resolve(SettingsSchemaBootstrapper::class)->extend(function (SettingsSchemaRegistry $registry): void {
    // This runs after all packages are registered
    $registry->register('admin', MyDynamicSchema::class);
});
```

## Registry API Reference

### SettingsSchemaRegistry Methods

#### `register(string $group, string $schemaClass, ?string $key = null): void`

Register a new schema for a group.

**Parameters:**

- `$group` - Settings group identifier (e.g., 'core', 'admin', 'frontend')
- `$schemaClass` - Fully qualified class name implementing `HasSchema`
- `$key` - Optional unique identifier (defaults to class basename)

**Example:**

```php
$registry->register('admin', AdminSettingsSchema::class);
$registry->register('admin', CustomSchema::class, 'my_custom');
```

#### `registerSettingsClass(string $group, string $settingsClass): void`

Register the primary settings class for a group.

**Parameters:**

- `$group` - Settings group identifier
- `$settingsClass` - Fully qualified settings class name

**Example:**

```php
$registry->registerSettingsClass('admin', AdminSettings::class);
```

#### `replace(string $group, string $schemaClass, string $key): void`

Replace an existing schema in a group.

**Parameters:**

- `$group` - Settings group identifier
- `$schemaClass` - New schema class
- `$key` - Key of the schema to replace (must exist)

**Throws:** `InvalidArgumentException` if key doesn't exist

**Example:**

```php
$registry->replace('admin', NewAdminSchema::class, 'AdminSettingsSchema');
```

#### `remove(string $group, string $key): void`

Remove a specific schema from a group.

**Parameters:**

- `$group` - Settings group identifier
- `$key` - Schema key to remove

**Example:**

```php
$registry->remove('admin', 'AdminSettingsSchema');
```

#### `removeGroup(string $group): void`

Remove all schemas from a group.

**Parameters:**

- `$group` - Settings group identifier

**Example:**

```php
$registry->removeGroup('admin');
```

#### `getSchemas(string $group): array`

Get all schema classes for a group.

**Parameters:**

- `$group` - Settings group identifier

**Returns:** `array<string, class-string<HasSchema>>`

**Example:**

```php
$schemas = $registry->getSchemas('admin');
foreach ($schemas as $key => $schemaClass) {
    // Process each schema
}
```

#### `getSchema(string $group, string $key): ?string`

Get a specific schema by group and key.

**Parameters:**

- `$group` - Settings group identifier
- `$key` - Schema key

**Returns:** `class-string<HasSchema>|null`

**Example:**

```php
$schema = $registry->getSchema('admin', 'AdminSettingsSchema');
```

#### `getSettingsClass(string $group): ?string`

Get the primary settings class for a group.

**Parameters:**

- `$group` - Settings group identifier

**Returns:** `class-string|null`

**Example:**

```php
$settingsClass = $registry->getSettingsClass('admin');
```

#### `getGroups(): array`

Get all registered group names.

**Returns:** `array<string>`

**Example:**

```php
$groups = $registry->getGroups();
// ['core', 'admin', 'frontend', 'ai-orchestrator']
```

#### `hasGroup(string $group): bool`

Check if a group has any schemas registered.

**Parameters:**

- `$group` - Settings group identifier

**Returns:** `bool`

**Example:**

```php
if ($registry->hasGroup('admin')) {
    // Admin group exists
}
```

#### `all(): array`

Get all registered schemas across all groups.

**Returns:** `array<string, array<string, class-string<HasSchema>>>`

**Example:**

```php
$allSchemas = $registry->all();
// [
//     'core' => ['CoreSettingsSchema' => CoreSettingsSchema::class],
//     'admin' => ['AdminSettingsSchema' => AdminSettingsSchema::class],
// ]
```

## Package Integration Examples

### Core Package

The Core package registers the registry and bootstrapper:

```php
// In CapellServiceProvider::packageRegistered()
private function registerSettingsSchemaRegistry(): self
{
    $this->app->singleton(
        SettingsSchemaRegistry::class,
        fn (): SettingsSchemaRegistry => new SettingsSchemaRegistry(),
    );

    $this->app->singleton(
        SettingsSchemaBootstrapper::class,
        fn (): SettingsSchemaBootstrapper => new SettingsSchemaBootstrapper(
            resolve(SettingsSchemaRegistry::class),
        ),
    );

    return $this;
}
```

### Admin Package

The Admin package registers core and admin schemas:

```php
// In AdminServiceProvider::bootInstalledPackage()
private function registerSettingsSchemas(): self
{
    $registry = resolve(SettingsSchemaRegistry::class);

    $registry->registerSettingsClass('core', CoreSettings::class);
    $registry->register('core', CoreSettingsSchema::class);

    $registry->registerSettingsClass('admin', AdminSettings::class);
    $registry->register('admin', AdminSettingsSchema::class);

    return $this;
}
```

### Frontend Package

The Frontend package registers its own schemas:

```php
// In FrontendServiceProvider::bootInstalledPackage()
private function registerSettingsSchemas(): self
{
    $registry = resolve(SettingsSchemaRegistry::class);

    $registry->registerSettingsClass('frontend', FrontendSettings::class);
    $registry->register('frontend', FrontendSettingsSchema::class);

    return $this;
}
```

### Custom Package

Your package can add its own settings or extend existing ones:

```php
// In YourPackageServiceProvider::bootInstalledPackage()
private function registerSettingsSchemas(): self
{
    $registry = resolve(SettingsSchemaRegistry::class);

    // Add your own settings group
    $registry->registerSettingsClass('my_package', MyPackageSettings::class);
    $registry->register('my_package', MyPackageSettingsSchema::class);

    // Or extend an existing group
    $registry->register('admin', MyPackageAdminExtensionSchema::class, 'my_package_admin');

    return $this;
}
```

## Settings Page Integration

First-party settings groups use the registry through the admin `SettingsPage` tabs. Package-owned settings use the same registry through extension management modal surfaces:

1. **Surface Ownership** - The package registers a settings surface with `CapellAdmin::registerExtensionManagementSurface()`
2. **Icon & Label** - Supplied by the registered management surface
3. **Schema Composition** - All schemas for the page's group are merged together
4. **Form Hydration** - The registered settings class populates form values
5. **Saving** - Data is saved to the registered settings class for that group

## Configurators

Configurators are package-owned classes that build Filament schemas for configurable admin surfaces such as page types, widget types, layout containers, sites, languages, and themes. They implement `Capell\Admin\Contracts\ConfiguratorInterface`, expose a stable `getKey()`, provide a `getSort()` order, and return a configured `Schema` from `configure(Schema $schema, ?ConfiguratorContextData $context = null)`.

Use a configurator when the extension point is an existing admin editing surface. Use a settings schema when the package owns saved package configuration. Keep both focused on assembling Filament components; move business operations into Actions and keep user-facing text in translation files.

Configurator schemas follow the same field presentation rules as settings schemas: fields belong in contained `Section` components. `contained(false)` is only appropriate for chrome-free display inside another panel, not for standalone labels, helper text, or inputs.

## Testing

### Unit Testing the Registry

```php
use Capell\Core\Support\Settings\SettingsSchemaRegistry;

it('registers a schema for a group')
    ->tap(function (): void {
        $registry = new SettingsSchemaRegistry();
        $registry->register('admin', MockAdminSchema::class);

        expect($registry->hasGroup('admin'))->toBeTrue();
    });
```

### Feature Testing Settings Schemas

```php
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Livewire\Livewire;

it('displays settings schema in extension settings modal')
    ->tap(function (): void {
        Livewire::withQueryParams([
            'manage' => 'capell-app/your-package',
            'surface' => 'your_package',
        ])
            ->test(ExtensionsPage::class)
            ->assertMountedActionModalSee('Your package settings');
    });

it('registers schema in registry')
    ->tap(function (): void {
        $registry = resolve(SettingsSchemaRegistry::class);

        expect($registry->hasGroup('yourgroup'))->toBeTrue();
        expect($registry->getSettingsClass('yourgroup'))
            ->toBe(YourSettings::class);
    });
```

## Best Practices

### 1. Use Descriptive Group Names

Choose clear, unique group names:

- ✅ `'yourpackage'`, `'ai-orchestrator'`, `'ecommerce'`
- ❌ `'settings'`, `'config'`, `'options'`

### 2. Register Schemas in Boot

Always register schemas in `bootInstalledPackage()` or similar boot methods, not in `register()`.

### 3. One Settings Class Per Group

Each group should have exactly one settings class (for form hydration/saving), but can have multiple schema classes (for form building).

### 4. Explicit Keys for Important Schemas

When registering schemas that others might want to replace/remove, use explicit keys:

```php
$registry->register('admin', ImportantSchema::class, 'important_feature');
```

### 5. Document Extension Points

If your package allows schema extensions, document which groups and keys are available for replacement.

### 6. Validate Schema Classes

The registry validates that all schemas implement `HasSchema`. Ensure your schemas are properly typed:

```php
class MySchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            // Form components
        ];
    }
}
```

## Troubleshooting

### Schema Not Appearing

**Problem:** Your schema is registered but doesn't appear in the extension settings modal.

**Solutions:**

1. Ensure the group name matches exactly
2. Check that `registerSettingsClass()` was called
3. Verify the schema class implements `HasSchema`
4. Check that the package registered an explicit `ExtensionManagementSurfaceData::settings(...)` surface
5. Clear cache: `php artisan cache:clear`

### InvalidArgumentException

**Problem:** Exception thrown when registering or replacing schemas.

**Solutions:**

1. Check that the schema class exists and is autoloaded
2. Verify the schema implements `HasSchema`
3. For `replace()`, ensure the key exists before replacing

### Settings Not Saving

**Problem:** Changes in the settings modal don't persist.

**Solutions:**

1. Ensure `registerSettingsClass()` was called for the group
2. Check that the settings class extends `Spatie\LaravelSettings\Settings`
3. Verify database migrations have run for the settings table
4. Check `group()` method returns the correct group name

## Performance Considerations

### Registry Instantiation

The registry is instantiated fresh on each request - no caching is needed for package settings modal use cases.

### Schema Loading

Schemas are loaded and merged when building the form. For many schemas (>20), consider:

1. Lazy loading schema components
2. Conditionally hiding schemas based on package installation status

### Extension Callbacks

Extension callbacks via the bootstrapper run on every request. Keep them lightweight:

- ✅ Simple schema registration
- ❌ Heavy computation or database queries

## Related Documentation

- [Packages & Add-ons](../../../docs/packages.md)
- [Configuration Reference](../../../docs/development/configuration.md)
- [Extending Capell](../../core/docs/extending-capell.md)
