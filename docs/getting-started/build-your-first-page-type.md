# Build your first page blueprint

A page blueprint chooses editing and frontend behaviour for ordinary Page records. You can add website-specific fields while keeping Capell's page model, URLs and publication workflow.

Follow [Add a custom field to a page](add-a-custom-page-field.md) to add **Client name** to a case study. It covers the field schema, registration, persistence, validation and public output. [Choose a custom-field approach](../packages/custom-page-fields.md) compares configurators, schema extenders, metadata, typed properties, related models and component fields, with real extension examples.

The [starter kit](build-a-client-site.md) already includes a case-study blueprint, an application-owned configurator, client/year metadata and public rendering. Inspect that implementation if you are extending a starter project; do not add a duplicate client field.

Creating a blueprint is different from registering a new model subject with `PageTypeData`. A website-specific field on an ordinary page does not need a new subject. The current `capell:make-blueprint` scaffold only supplies a name and label class; it does not build the complete field workflow for you.

## Add a public widget

Widgets need the Layout Builder package, which the [guided installer](install.md) adds and the starter kit does not include. Three things connect a widget: a Blade component, a renderable registered under a key, and a widget record that names that key.

Create the Blade view `resources/views/widgets/project-note.blade.php`:

```blade
<p class="project-note">Talk to us about a similar project.</p>
```

Register it in `boot()` in `app/Providers/AppServiceProvider.php`:

```php
use Capell\Core\Data\RenderableDefinitionData;
use Capell\Core\Support\Renderables\RenderableRegistry;

app(RenderableRegistry::class)->register(new RenderableDefinitionData(
    key: 'project-note',
    type: 'layout-widget',
    blade: 'widgets.project-note',
));
```

The following placement example assumes the installed demo has a `breadcrumbs` widget and a `home` layout. Create the record and place it on that layout:

```bash
php artisan tinker --execute='
$blueprint = Capell\LayoutBuilder\Models\Widget::where("key", "breadcrumbs")->value("blueprint_id");
$widget = Capell\LayoutBuilder\Models\Widget::firstOrCreate(["key" => "project-note"], ["name" => "Project note", "blueprint_id" => $blueprint, "status" => true]);
$widget->update(["component" => "project-note"]);
$layout = Capell\Core\Models\Layout::where("key", "home")->first();
$containers = $layout->containers;
$containers["main"]["widgets"][] = ["widget_key" => "project-note"];
$layout->update(["containers" => $containers]);
'
php artisan cache:clear
```

Reload `/` and confirm the note appears. Run the placement once, because each run adds another copy to the layout.

## Next

- [Widget registration](../frontend/widget-registration.md) for resources and defaults
- [Build a client site](build-a-client-site.md)
