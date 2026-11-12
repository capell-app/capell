# Coming from Statamic

Statamic and Capell are both Laravel based. The main differences are that Capell stores content in the database and renders it with Blade.

Statamic's *blueprints* define fields. Capell's blueprints identify a page type, and its fields come from a configurator in PHP.

| Statamic | Capell | Where it lives |
| --- | --- | --- |
| Collection | Page type (blueprint) | [Build your first page type](build-your-first-page-type.md) |
| Entry | Page | **Pages** in Admin |
| Blueprint and fieldset | Configurator fields, stored in `meta` | `ServicePageConfigurator` in that guide |
| Antlers template | Blade view | `resources/views/capell` |
| Tag | Blade component or render data contributor | Templates never query models |
| Bard or Replicator set | Widget on a layout | [Widget registration](../frontend/widget-registration.md) |
| Addon | Composer package | [Packages](../packages/README.md) |
| Flat-file content in Git | Database rows and page history | Back up the database |

## Convert a sample site

For a `services` collection with a `price` field:

1. Create the page type and field by following [Build your first page type](build-your-first-page-type.md).
2. Create one page per entry in Admin, and set its address to the entry's URL.
3. Rewrite the Antlers template in Blade, using values from the render data contributor.
4. Move Bard sets to widgets, then check each address anonymously.

## Related guides

- [Build a client site](build-a-client-site.md)
- [Comparing Capell](comparing-capell.md)
