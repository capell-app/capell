# Extending Capell: Patterns & Examples

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

Widgets can be placed on pages by packages that provide placement UIs.

```php
<?php

declare(strict_types=1);

namespace App\Widgets;

use Capell\Core\Blueprints\AbstractWidget;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ColorPicker;
use Filament\Schemas\Schema;

class CallToActionWidget extends AbstractWidget
{
    public static function getName(): string
    {
        return 'Call To Action';
    }

    public static function schema(Schema $schema): array
    {
        return [
            TextInput::make('title')->required(),
            TextInput::make('button_text')->required(),
            TextInput::make('button_url')->url()->required(),
            ColorPicker::make('background_color'),
        ];
    }

    // Blade view for frontend rendering
    public static function getView(): string
    {
        return 'elements.call-to-action';
    }
}
```

Register: `CapellCore::registerWidget(CallToActionLayoutElement::class);`

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
use Capell\Core\Support\Settings\SettingsSchemaRegistry;

private function registerSettingsSchemas(): void
{
    $registry = resolve(SettingsSchemaRegistry::class);
    $registry->registerSettingsClass('my_package', MyPackageSettings::class);
    $registry->register('my_package', MyPackageSettingsSchema::class);
}
```

Create a migration for the settings:

```bash
php artisan vendor:publish --provider="Spatie\LaravelSettings\LaravelSettingsServiceProvider" --tag="migrations"
# Or create manually
php artisan make:migration create_my_package_settings
```

## Extending an Existing Schema

Use the SettingsSchemaBootstrapper to modify existing schemas after they're registered:

```php
use Capell\Core\Support\Settings\SettingsSchemaBootstrapper;
use Filament\Forms\Components\TextInput;

$bootstrapper = resolve(SettingsSchemaBootstrapper::class);

$bootstrapper->extend('core', function (array $components) {
    return array_merge($components, [
        TextInput::make('my_custom_field')->label('Custom Field'),
    ]);
});
```

## Creating a Schema Extender (for Admin form-builder)

Schema extenders modify Filament resource form-builder (e.g., add fields to the Page form). Implement `PageSchemaExtender` and tag with `PageSchemaExtender::TAG`:

```php
<?php

declare(strict_types=1);

namespace App\Schemas;

use Capell\Admin\Contracts\PageSchemaExtender;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MyPageSchemaExtender implements PageSchemaExtender
{
    public function extend(Schema $schema): array
    {
        return [
            TextInput::make('custom_seo_title')
                ->label('Custom SEO Title'),
        ];
    }
}
```

Register in service provider:

```php
$this->app->tag([MyPageSchemaExtender::class], PageSchemaExtender::TAG);
```

The core admin resolves all tagged extenders at runtime:

```php
collect(app()->tagged(PageSchemaExtender::TAG))
    ->each(fn (PageSchemaExtender $extender) => $extender->extend($schema));
```

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
