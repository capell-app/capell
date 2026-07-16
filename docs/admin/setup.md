# Admin Setup

![Capell Admin Setup screenshot](../images/admin-settings.png)

`capell:admin-setup` prepares the Capell Admin package and can integrate Capell Admin into a Filament panel without replacing custom panel configuration.

```sh
php artisan capell:admin-setup
```

Use `--integration-only` when the content, language, theme, and Shield setup has already been handled:

```sh
php artisan capell:admin-setup --integration-only --force
```

## Panel Integration Options

| Option                     | Description                                                                    |
| -------------------------- | ------------------------------------------------------------------------------ |
| `--panel=`                 | Panel provider filename, class name, panel ID, relative path, or absolute path |
| `--schemas=`               | `auto` or comma-separated `path=namespace` pairs                               |
| `--no-colors`              | Skip `FilamentColorEnum::colors()`                                             |
| `--no-widgets`             | Skip `CapellAdmin::getWidgets()`                                               |
| `--no-navigation`          | Skip Capell navigation items and groups                                        |
| `--preview`                | Show the result table without writing the provider                             |
| `--force`                  | Skip confirmation prompts                                                      |
| `--skip-panel-integration` | Run only the existing admin content/auth setup                                 |

Schema discovery defaults to:

```sh
--schemas=Filament/Resources=App\\Filament\\Resources
```

Create a Filament panel first if the command reports no panel providers:

```sh
php artisan make:filament-panel admin
```

Filament panel installation docs: <https://filamentphp.com/docs/5.x/panels/installation>

## Manual Snippets

If a provider uses a shape the editor cannot safely update, add the same calls manually:

```php
use Capell\Admin\Enums\FilamentColorEnum;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Filament\Plugin\CapellAdminPlugin;

return $panel
    ->colors(FilamentColorEnum::colors())
    ->navigationItems(CapellAdmin::getNavigationItems())
    ->navigationGroups(CapellAdmin::getNavigationGroups())
    ->plugin(CapellAdminPlugin::make()
        ->discoverConfigurators(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources'))
    ->widgets([
        ...CapellAdmin::getWidgets(),
    ]);
```

`CapellAdminPlugin` also registers Capell's required Filament plugins when they
are not already present, including Shield, translatable support, record
switching, clear-cache, and the welcome tour plugin. Do not add
`FilamentTourPlugin::make()` separately unless you need custom tour plugin
configuration before Capell registers its default.

To change the admin URL, configure the panel entrypoint through
`CAPELL_ADMIN_PATH` and `CAPELL_ADMIN_DOMAIN` rather than hard-coding `/admin`.
See [Admin domain and path](admin-domain.md).

Reruns are idempotent. Existing custom colors are respected, existing Capell plugin/widgets/navigation calls are reported as already applied, and custom panel code is left for manual review when needed.

## Failure Categories

| Category            | Meaning                                                            |
| ------------------- | ------------------------------------------------------------------ |
| `permission_denied` | The provider or backup path cannot be written                      |
| `parse_error`       | The provider PHP could not be parsed                               |
| `missing_panel`     | No Filament panel provider was discovered                          |
| `unsupported_shape` | The `panel()` method is not a single fluent return chain           |
| `existing_conflict` | Existing code needs manual review before Capell calls can be added |
| `validation`        | Command input such as `--schemas` was invalid                      |

## Next

- [Admin interface](interface.md)
- [Users and roles](users-and-roles.md)
