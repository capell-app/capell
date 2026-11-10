# Extending Capell: Patterns & Examples

## Contents

- [Creating a Custom Page Type](#creating-a-custom-page-type)
- [Creating a Custom Widget](#creating-a-custom-widget)
- [Creating a Settings Schema](#creating-a-settings-schema)
- [Extending an Existing Schema](#extending-an-existing-schema)
- [Creating a Schema Extender (for Admin form-builder)](#creating-a-schema-extender-for-admin-form-builder)
- [Creating a New Add-on Package](#creating-a-new-add-on-package)
- [Local Development Setup (Path Repositories)](#local-development-setup-path-repositories)
- [Extending Core Types/Resources](#extending-core-typesresources)
- [Publishing Core Schemas](#publishing-core-schemas)

## Creating a Custom Page Type

Page types describe the model subject that can be edited as a page-like blueprint. Register them with `PageTypeData`; form fields and admin behavior are extended separately through schema extenders and admin contracts.

```php
<?php

declare(strict_types=1);

namespace App\Types;

use Illuminate\Database\Eloquent\Model;

final class ProductPage extends Model
{
    protected $table = 'product_pages';
}
```

Register in your `AppServiceProvider`:

```php
use App\Types\ProductPage;
use Capell\Core\Data\PageTypeData;
use Capell\Core\Facades\CapellCore;

public function register(): void
{
    CapellCore::registerPageType(new PageTypeData(
        name: 'product',
        model: ProductPage::class,
        label: 'Products',
    ));
}
```

Use `PageSchemaExtender::TAG` for fields, `PageTableExtender::TAG` for admin table query changes, and frontend component registration for public rendering. Do not publish core schemas to add package fields.

## Creating a Custom Widget

Choose the component surface first. Layout Builder widget records use their own blueprint, placement and metadata. Inline content widgets expose a Filament Builder block and typed input/render contracts; their values belong to a component occurrence, not to Page metadata.

For an inline content widget, the current editor contract is `FilamentWidget`:

```php
<?php

declare(strict_types=1);

namespace App\Widgets;

use Capell\Admin\Contracts\Widgets\FilamentWidget;
use Filament\Forms\Components\Builder\Block;
use Filament\Forms\Components\TextInput;
use Override;

final class ClientCreditWidget implements FilamentWidget
{
    #[Override]
    public static function getWidgetName(): string
    {
        return 'my-site.client-credit';
    }

    #[Override]
    public static function make(): Block
    {
        return Block::make(self::getWidgetName())
            ->label(__('case-study.details'))
            ->schema([
                TextInput::make('client_name')
                    ->label(__('case-study.client_name'))
                    ->required()
                    ->maxLength(120),
            ]);
    }
}
```

Define those translation labels in your application. This class supplies only the editor block. Register the widget's typed input, render handler and public output through the content-widget contribution contract; a form class alone does not complete registration or rendering. The Countdown extension is a concrete example of those separate contracts.

For a Layout Builder widget, follow the [widget registration guide](https://github.com/capell-app/capell/blob/main/docs/frontend/widget-registration.md). Do not use the removed `Capell\Core\Blueprints\AbstractWidget` / `CapellCore::registerWidget()` pattern. The [custom-field decision guide](https://github.com/capell-app/capell/blob/main/docs/packages/custom-page-fields.md) distinguishes component-instance fields from page-wide fields.

## Creating a Settings Schema

Settings allow per-package configurable options in the admin panel.

### 1. Create the Settings Class (Spatie Settings)

```php
<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class MyPackageSettings extends Settings
{
    public string $api_key = '';
    public bool $enable_feature = false;
    public int $max_items = 10;

    public static function group(): string
    {
        return 'my_package';
    }
}
```

### 2. Create the Settings Schema (Filament form)

```php
<?php

declare(strict_types=1);

namespace App\Filament\Settings;

use App\Settings\MyPackageSettings;
use Capell\Admin\Filament\Contracts\HasSchema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput as NumberInput;
use Filament\Schemas\Schema;

class MyPackageSettingsSchema implements HasSchema
{
    public static function make(Schema $schema): array
    {
        return [
            TextInput::make('api_key')
                ->label('API Key')
                ->password()
                ->required(),
            Toggle::make('enable_feature')
                ->label('Enable Feature'),
            NumberInput::make('max_items')
                ->label('Maximum Items')
                ->numeric()
                ->minValue(1),
        ];
    }
}
```

### 3. Register in Service Provider

```php
protected function bootInstalledPackage(): self
{
    $surface = $this->surface();
    $surface->settingsClass('my_package', MyPackageSettings::class);
    $surface->settingsSchema('my_package', MyPackageSettingsSchema::class);

    return $this;
}
```

Settings contributions are boot metadata. They are applied immediately in installed package provider load order and remain stable across Octane requests. If a keyed contribution intentionally replaces another package's schema, declare the package dependency that establishes that order.

Create a migration for the settings:

```bash
php artisan vendor:publish --provider="Spatie\LaravelSettings\LaravelSettingsServiceProvider" --tag="migrations"
# Or create manually
php artisan make:migration create_my_package_settings
```

## Extending an Existing Schema

Contribute another keyed schema to the existing group from the package provider:

```php
protected function bootInstalledPackage(): self
{
    $this->surface()->settingsSchema(
        'core',
        MyPackageCoreSettingsSchema::class,
        'my-package',
    );

    return $this;
}
```

The registered schema class owns its Filament components. Do not defer registry mutation through an additional boot callback.

## Creating a Schema Extender (for Admin form-builder)

Use the abstract base when contributing one hook. It supplies the other interface methods without replacing existing components.

```php
<?php

declare(strict_types=1);

namespace App\Schemas;

use Capell\Admin\Support\Schemas\AbstractPageSchemaExtender;
use Capell\Core\Models\Page;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Override;

final class CaseStudyPageSchemaExtender extends AbstractPageSchemaExtender
{
    #[Override]
    public function extendTabs(Schema $schema, array $tabs): array
    {
        $record = $schema->getRecord();

        if (! $record instanceof Page || $record->blueprint?->key !== 'case-study') {
            return $tabs;
        }

        return [
            ...$tabs,
            Tab::make(__('case-study.details'))->schema([
                TextInput::make('meta.case_study.client_name')
                    ->label(__('case-study.client_name'))
                    ->required()
                    ->maxLength(120),
            ]),
        ];
    }
}
```

Define the labels in `lang/en/case-study.php`, then register in the application's provider:

```php
use App\Schemas\CaseStudyPageSchemaExtender;
use Capell\Admin\Contracts\Extenders\PageSchemaExtender;

$this->app->tag([CaseStudyPageSchemaExtender::class], PageSchemaExtender::TAG);
```

For an optional package, register installed-only contributions from `bootInstalledRuntime()` rather than an unconditional Composer-discovered provider boot.

The top-level field persists in `Page.meta`; no migration or arbitrary new attribute is implied. A field at that relative path inside `extendTranslationComponentsForHook()` persists in each `Translation.meta` instead. Choose the intended scope, enforce write rules and hydrate public display data before Blade. Keep unrelated tabs with `...$tabs`; add a site condition if the blueprint spans sites but the field should not.

After changing a cached admin registration, clear and rebuild the configurator cache:

```bash
php artisan capell:admin-clear-cache
php artisan capell:admin-cache-configurators
```

See the [complete Client name walkthrough](https://github.com/capell-app/capell/blob/main/docs/getting-started/add-a-custom-page-field.md) for validation, persistence and public output.

## Creating a New Add-on Package

Standard Laravel package structure:

```
my-package/
├── composer.json
├── src/
│   ├── Providers/
│   │   └── MyPackageServiceProvider.php
│   ├── Models/
│   ├── Filament/
│   │   ├── Resources/
│   │   └── Settings/
│   ├── Console/Commands/
│   │   └── InstallCommand.php
│   ├── Settings/
│   └── Database/
│       └── migrations/
├── resources/
│   └── views/
└── tests/
```

### Minimal Service Provider

```php
<?php

declare(strict_types=1);

namespace MyVendor\MyPackage\Providers;

use Illuminate\Support\ServiceProvider;

class MyPackageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/my-package.php', 'my-package');
        $this->registerSettingsSchemas();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'my-package');

        $this->registerFilamentResources();
        $this->registerCommands();
    }

    private function registerSettingsSchemas(): void
    {
        // ... see settings pattern above
    }

    private function registerFilamentResources(): void
    {
        // Register any Filament resources with the admin panel
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \MyVendor\MyPackage\Console\Commands\InstallCommand::class,
            ]);
        }
    }
}
```

### Minimal Install Command

```php
<?php

declare(strict_types=1);

namespace MyVendor\MyPackage\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'my-package:install';
    protected $description = 'Install My Package';

    public function handle(): int
    {
        $this->info('Installing My Package...');

        $this->call('migrate');
        $this->call('vendor:publish', [
            '--provider' => 'MyVendor\MyPackage\Providers\MyPackageServiceProvider',
        ]);

        $this->info('My Package installed successfully!');

        return self::SUCCESS;
    }
}
```

## Local Development Setup (Path Repositories)

To develop a Capell package locally with symlinks, add a path repository to your app's `composer.json` pointing at your local checkout:

```json
"repositories": [
    {
        "type": "path",
        "url": "../path/to/your/package",
        "options": { "symlink": true }
    }
]
```

Then run `composer update` to symlink the package. Changes to source files are immediately reflected.

## Extending Core Types/Resources

Prefer targeted extension points over replacing core resources. Use model interceptors for model behavior, schema extenders for form fields, and admin surface contributions for pages/resources:

```php
use App\Interceptors\ProductPageInterceptor;
use Capell\Core\Models\Page;
use Capell\Core\Facades\CapellCore;

CapellCore::registerModelInterceptor(
    Page::class,
    ProductPageInterceptor::class,
);
```

Replacing core types or schemas is not a supported upgrade path.

## Publishing Core Schemas

Do not use `php artisan capell:admin-publish-schemas`. It breaks package upgrades by copying internal schema files into the host app. Use the documented extender contracts instead.
