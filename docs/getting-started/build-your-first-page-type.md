# Build your first page type

A page type gives editors their own fields and gives the public site its own output. This guide adds a **Service** page type with a **Price** field to a [starter-kit project](build-a-client-site.md), then adds a public widget to an application that uses Layout Builder. The starter kit's published smoke test applies the Service steps to a new project and requests the page anonymously.

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

Add this import to `app/Providers/AppServiceProvider.php`:

```php
use App\Filament\Configurators\Pages\ServicePageConfigurator;
```

Then register the configurator in its `boot()` method, beside the case study configurator:

```php
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

## 5. Choose how to store other fields

**Price** above is a plain value in the page's `meta` column, edited through a configurator. To add a field to an existing page type without replacing its editor, follow [Add a custom field to a page](add-a-custom-page-field.md), which adds **Client name** to a case study with a schema extender. [Choose a custom-field approach](../packages/custom-page-fields.md) compares configurators, schema extenders, metadata, typed properties, related models and component fields, with real extension examples.

The starter kit already includes a case-study page type with client and year fields. Inspect that implementation before adding a second client field.

A page type here is a blueprint on ordinary Page records. Registering a new model subject with `BlueprintSubjectDescriptorData` and `BlueprintSubjectRegistry` is a different, larger step that a website-specific field does not need.

## 6. Add a public widget

Widgets need the Layout Builder package, which the [guided installer](install.md) adds and the starter kit does not include. On the starter kit, install it first:

```bash
composer require capell-app/layout-builder
php artisan capell:extension-install capell-app/layout-builder
```

Three things connect a widget: a Blade component, a renderable registered under a key, and a widget record that names that key. The layout must also print its containers.

Create the Blade component `resources/views/components/widgets/project-note.blade.php`. The renderable's `blade` value is a component name, so the file belongs under `components`:

```blade
<p class="project-note">Talk to us about a similar project.</p>
```

Add these imports to `app/Providers/AppServiceProvider.php`:

```php
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Support\Renderables\RenderableRegistry;
```

Then register the renderable in its `boot()` method:

```php
app(RenderableRegistry::class)->register(new RenderableDefinitionData(
    key: 'project-note',
    type: 'layout-widget',
    blade: 'widgets.project-note',
));
```

The starter kit's layout does not print containers yet. In `resources/views/capell/themes/capell-app/studio/layout.blade.php`, add this inside `<main id="main">`, before the `<h1>`:

```blade
<x-capell-layout-builder::layout.area :layout="$layout" :public-render-data="$publicRenderData" />
```

Create the widget record and place it in the `main` container of the starter's `studio` layout:

```bash
php artisan tinker --execute='
$blueprint = Capell\Core\Models\Blueprint::firstOrCreate(["key" => "note", "type" => Capell\LayoutBuilder\Enums\LayoutTypeEnum::Widget->value], ["name" => "Note", "default" => true]);
$widget = Capell\LayoutBuilder\Models\Widget::firstOrCreate(["key" => "project-note"], ["name" => "Project note", "blueprint_id" => $blueprint->id, "status" => true]);
$widget->update(["component" => "project-note"]);
$layout = Capell\Core\Models\Layout::where("key", "studio")->first();
$containers = $layout->containers ?? [];
$placed = collect($containers["main"]["widgets"] ?? [])->contains(fn ($item) => ($item["widget_key"] ?? null) === "project-note");
if (! $placed) {
    $containers["main"]["widgets"][] = ["widget_key" => "project-note"];
    $layout->update(["containers" => $containers]);
}
'
php artisan cache:clear
```

Reload `/` and confirm the note appears above the page heading. The placement is safe to run again, because it adds the widget only when the layout does not already contain it. On a site that already has a layout with containers, change `"studio"` to your layout key and skip the layout edit.

## Related guides

- [Widget registration](../frontend/widget-registration.md) for resources and defaults
- [Build a client site](build-a-client-site.md)
