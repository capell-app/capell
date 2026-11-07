# Build your first page type

A page type gives editors their own fields and gives the public site its own output. This guide adds a **Service** page type to a [starter-kit project](build-a-client-site.md), then adds a public widget to an application that uses Layout Builder.

`capell:make-blueprint` currently writes only a name and label class, so this guide does not use it.

## 1. Seed the page

In `app/Actions/SeedStarterSiteAction.php`, add the page to the list:

```php
['service', 'Brand Sprint', '/services/brand-sprint', 'service_title', 'service_summary'],
```

In the same file, choose its editor and starting values:

```php
'admin' => ['configurator' => match ($key) {
    'case-study' => 'CaseStudy',
    'service' => 'Service',
    default => 'Default',
}],
```

```php
'meta' => match ($key) {
    'case-study' => ['client' => 'Field Notes', 'year' => '2026'],
    'service' => ['price' => 'From £4,000'],
    default => [],
},
```

Add its text to `lang/en/starter.php`:

```php
'service' => 'Service',
'price' => 'Price',
'service_title' => 'Brand Sprint',
'service_summary' => 'Two weeks to a clearer brand.',
```

## 2. Add the editor field

Create `app/Filament/Configurators/Pages/ServicePageConfigurator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Configurators\Pages;

use Capell\Admin\Filament\Configurators\Pages\DefaultPageConfigurator;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Override;

final class ServicePageConfigurator extends DefaultPageConfigurator
{
    #[Override]
    protected function getEditFormSchema(Schema $schema): array
    {
        return [
            ...parent::getEditFormSchema($schema),
            Section::make(__('starter.service'))->schema([
                TextInput::make('meta.price')->label(__('starter.price'))->required()->maxLength(50),
            ]),
        ];
    }
}
```

Register it in the `boot()` method of `app/Providers/AppServiceProvider.php`, beside the case study configurator:

```php
use App\Filament\Configurators\Pages\ServicePageConfigurator;

CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::configurator(
    class: ServicePageConfigurator::class, group: 'Pages', name: 'Service', owner: 'app',
));
```

## 3. Pass the value to the public page

Public Blade receives hydrated values and never queries models. In `contribute()` in `app/Support/StudioRenderDataContributor.php`, add:

```php
'price' => $context->page->meta['price'] ?? '',
```

In `resources/views/capell/themes/capell-app/studio/layout.blade.php`, add this above the contact form condition:

```blade
@if($studio->kind === 'service')
    <p class="price">{{ __('starter.price') }}: {{ $studio->price }}</p>
@endif
```

## 4. Run setup and check it

Setup is safe to repeat. It keeps edited content and creates what is missing.

```bash
composer run setup
php artisan serve
```

Open `/services/brand-sprint`. In Admin, open **Pages**, edit **Brand Sprint**, change **Price** and save. Reload the public page in a private window and confirm the new price.

## 5. Add a public widget

Widgets need the Layout Builder package, which the [guided installer](install.md) adds and the starter kit does not include. Three things connect a widget: a Blade component, a renderable registered under a key, and a widget record that names that key.

Create `resources/views/components/widgets/price-note.blade.php`:

```blade
<p class="price-note">Prices include VAT.</p>
```

Register it in `boot()` in `app/Providers/AppServiceProvider.php`:

```php
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Support\Renderables\RenderableRegistry;

app(RenderableRegistry::class)->register(new RenderableDefinitionData(
    key: 'price-note',
    type: 'layout-widget',
    blade: 'widgets.price-note',
));
```

Then create the record and place it on the Home layout:

```bash
php artisan tinker --execute='
$blueprint = Capell\LayoutBuilder\Models\Widget::where("key", "breadcrumbs")->value("blueprint_id");
$widget = Capell\LayoutBuilder\Models\Widget::firstOrCreate(["key" => "price-note"], ["name" => "Price note", "blueprint_id" => $blueprint, "status" => true]);
$widget->update(["component" => "price-note"]);
$layout = Capell\Core\Models\Layout::where("key", "home")->first();
$containers = $layout->containers;
$containers["main"]["widgets"][] = ["widget_key" => "price-note"];
$layout->update(["containers" => $containers]);
'
php artisan cache:clear
```

Reload `/` and confirm the note appears. Run the placement once, because each run adds another copy to the layout.

## Next

- [Widget registration](../frontend/widget-registration.md) for resources and defaults
- [Build a client site](build-a-client-site.md)
