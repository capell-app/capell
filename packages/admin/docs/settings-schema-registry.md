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

2. **PackageSurfaceRegistrar** (`Capell\Core\Support\Packages\PackageSurfaceRegistrar`)
    - Is the canonical write path for package-owned settings
    - Is available through `AbstractPackageServiceProvider::surface()`
    - Preserves provider load order: contributions are applied when each installed package boots

3. **AdminBridgeRegistrar** (`Capell\Admin\Support\Bridges\AdminBridgeRegistrar`)
    - Is the canonical write path for settings supplied by an external admin integration
    - Provides the same settings class, metadata, and schema contribution trio

4. **HasSchema Contract** (`Capell\Admin\Filament\Contracts\HasSchema`)
    - Interface all settings schemas must implement
    - Defines `make(Schema $schema): array` method

## Basic Usage

### Registering a Settings Schema

In your package's service provider:

```php
use Capell\YourPackage\Filament\Settings\YourSettingsSchema;
use Capell\YourPackage\Settings\YourSettings;
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Filament\Support\Icons\Heroicon;

private function registerSettingsSchemas(): self
{
    $surface = $this->surface();

    // Register the settings class (for form hydration/saving)
    $surface->settingsClass('yourgroup', YourSettings::class);

    $surface->settingsMetadata(new SettingsGroupMetadata(
        group: 'yourgroup',
        label: 'your-package::settings.title',
        icon: Heroicon::OutlinedCog6Tooth,
        navigationGroup: 'capell-admin::navigation.group_system',
        packageName: 'capell-app/your-package',
    ));

    // Register the schema class (for form building)
    $surface->settingsSchema('yourgroup', YourSettingsSchema::class);

    return $this;
}
```

### Creating a Package Settings Modal

Package settings are exposed from the Extensions page as an explicit opt-in modal surface. Register the settings class, schema, metadata, then register the management surface:

```php
<?php

declare(strict_types=1);

use Capell\Admin\Data\Extensions\ExtensionManagementSurfaceData;
use Capell\Admin\Support\Bridges\AdminBridgeRegistrar;
use Capell\Core\Support\Settings\SettingsGroupMetadata;

$registrar = resolve(AdminBridgeRegistrar::class);
$registrar->settingsClass('your_package', YourPackageSettings::class);
$registrar->settingsMetadata(new SettingsGroupMetadata(
    group: 'your_package',
    label: 'Your package settings',
    packageName: 'capell-app/your-package',
));
$registrar->settingsSchema('your_package', YourPackageSettingsSchema::class);

$registrar->extensionManagementSurface(ExtensionManagementSurfaceData::settings(
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
$surface = $this->surface();

// Core schema
$surface->settingsSchema('admin', AdminCoreSchema::class, 'core');

// Additional schema from a package
$surface->settingsSchema('admin', AdminExtendedSchema::class, 'extended');
```

Both schemas will appear on the package-owned settings modal for that group.

### Replacing an Existing Schema

To replace a keyed schema, register the same group and key later in provider load order:

```php
// Replace the core admin schema with a custom one
$this->surface()->settingsSchema('admin', CustomAdminSchema::class, 'AdminSettingsSchema');
```

Key collisions are deterministic: the later installed-package provider contribution wins. Declare package dependencies when one package must contribute after another; do not defer schema writes through an extra boot callback.

### Removing a Schema

Removal is an internal registry operation used for lifecycle and test cleanup. Package providers should contribute or replace a stable keyed schema instead of mutating another package's group after registration.

### Dynamic Schema Registration

Register schemas from `bootInstalledPackage()` through `surface()`. Capell applies contributions immediately in installed package provider load order, so the registry is complete when application boot finishes:

```php
protected function bootInstalledPackage(): self
{
    $this->surface()->settingsSchema('admin', MyDynamicSchema::class, 'my-package');

    return $this;
}
```

## Contribution API Reference

Package providers use these `PackageSurfaceRegistrar` methods. An `AdminBridgeRegistrar` exposes the same three settings methods for external admin integrations.

#### `settingsSchema(string $group, string $schemaClass, ?string $key = null): self`

Register a new schema contribution for a group.

**Parameters:**

- `$group` - Settings group identifier (e.g., 'core', 'admin', 'frontend')
- `$schemaClass` - Fully qualified class name implementing `HasSchema`
- `$key` - Optional unique identifier (defaults to class basename)

**Example:**

```php
$this->surface()->settingsSchema('admin', AdminSettingsSchema::class);
$this->surface()->settingsSchema('admin', CustomSchema::class, 'my_custom');
```

#### `settingsClass(string $group, string $settingsClass): self`

Register the primary settings class for a group.

**Parameters:**

- `$group` - Settings group identifier
- `$settingsClass` - Fully qualified settings class name

**Example:**

```php
$this->surface()->settingsClass('admin', AdminSettings::class);
```

#### `settingsMetadata(SettingsGroupMetadata $metadata): self`

Register the label, icon, navigation, and package ownership metadata for a settings group.

### SettingsSchemaRegistry Read Methods

Consumers may resolve the registry to read the completed boot metadata. Registry mutation methods are internal implementation details; package code writes through one of the canonical registrars above.

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

The Core package binds the registry and the package surface registrar writes to it:

```php
// In CapellServiceProvider::packageRegistered()
private function registerSettingsSchemaRegistry(): self
{
    $this->app->singleton(
        SettingsSchemaRegistry::class,
        fn (): SettingsSchemaRegistry => new SettingsSchemaRegistry,
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
    $surface = $this->surface();

    $surface->settingsClass('core', CoreSettings::class);
    $surface->settingsSchema('core', CoreSettingsSchema::class);

    $surface->settingsClass('admin', AdminSettings::class);
    $surface->settingsSchema('admin', AdminSettingsSchema::class);

    return $this;
}
```

### Frontend Package

The Frontend package registers its own schemas:

```php
// In FrontendServiceProvider::bootInstalledPackage()
private function registerSettingsSchemas(): self
{
    $surface = $this->surface();

    $surface->settingsClass('frontend', FrontendSettings::class);
    $surface->settingsSchema('frontend', FrontendSettingsSchema::class);

    return $this;
}
```

### Custom Package

Your package can add its own settings or extend existing ones:

```php
// In YourPackageServiceProvider::bootInstalledPackage()
private function registerSettingsSchemas(): self
{
    $surface = $this->surface();

    // Add your own settings group
    $surface->settingsClass('my_package', MyPackageSettings::class);
    $surface->settingsSchema('my_package', MyPackageSettingsSchema::class);

    // Or extend an existing group
    $surface->settingsSchema('admin', MyPackageAdminExtensionSchema::class, 'my_package_admin');

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

When registering schemas that others might intentionally replace, use explicit keys:

```php
$this->surface()->settingsSchema('admin', ImportantSchema::class, 'important_feature');
```

### 5. Document Extension Points

If your package allows schema extensions, document which groups and keys are available for keyed contributions.

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

- [Packages and extensions](../../../docs/packages/catalog.md)
- [Configuration Reference](../../../docs/development/configuration.md)
- [Extending Capell](../../core/docs/extending-capell.md)
