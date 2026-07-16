# Actions, Data, And Settings

Capell packages use Actions for behavior and Data objects for typed boundaries.

## Actions

Put package behavior in `src/Actions`.

```php
<?php

declare(strict_types=1);

namespace Capell\Example\Actions;

use Lorisleiva\Actions\Concerns\AsObject;

final class BuildExampleReportAction
{
    use AsObject;

    public function handle(): mixed
    {
        return ExampleModel::query();
    }
}
```

Filament pages, commands, listeners, and controllers call `::run()` or `::dispatch()`. They should not contain domain logic.

## Data Objects

Use `src/Data` for structured input and output:

```php
<?php

declare(strict_types=1);

namespace Capell\Example\Data;

use Spatie\LaravelData\Data;

final class ExampleHealthData extends Data
{
    public function __construct(
        public string $status,
        public int $issueCount,
    ) {}
}
```

Use Data objects across HTTP, Filament, Livewire, JSON casts, and package boundaries.

## Settings

Settings need a Spatie settings class, a settings migration, a Filament schema, and a package-owned extension settings page. The global Capell Settings page is reserved for core settings only.

```php
final class ExampleSettings extends Settings
{
    public bool $enabled = true;

    public static function group(): string
    {
        return 'example';
    }
}
```

Register settings from a provider:

```php
use Capell\Core\Support\Settings\SettingsGroupMetadata;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;
use Filament\Support\Icons\Heroicon;

$registry = resolve(SettingsSchemaRegistry::class);
$registry->registerSettingsClass('example', ExampleSettings::class);
$registry->registerMetadata(new SettingsGroupMetadata(
    group: 'example',
    label: 'example::settings.title',
    icon: Heroicon::OutlinedCog6Tooth,
    navigationGroup: 'capell-admin::navigation.group_system',
    packageName: 'capell-app/example',
));
$registry->register('example', ExampleSettingsSchema::class);
```

Expose package settings through a Filament page that extends `Capell\Admin\Filament\Pages\AbstractPackageSettingsPage` and register it with `CapellAdmin::registerExtensionPage($packageName, YourPackageSettingsPage::class)`. This adds the page to Filament, lists the package on the Extensions management page with a direct **Edit** action, and adds accessible registered pages to the grouped Filament sub-navigation on the Extensions page. Packages with multiple registered pages use the first page as the primary edit target and show the others as direct secondary links.

Settings schemas should return contained Filament sections:

```php
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

Section::make(__('capell-example::settings.general'))
    ->columnSpanFull()
    ->schema([
        TextInput::make('api_key')
            ->label(__('capell-example::settings.api_key')),
    ])
    ->columns(2);
```

Do not return bare fields or bare grids from a settings schema. Do not use `contained(false)` around normal fields unless another contained section already provides the background. Labels, helper text, toggles, and inputs must remain readable in both light and dark mode.
