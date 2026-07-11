# Artisan Commands Reference

![Capell Artisan Commands Reference screenshot](../images/admin-dashboard.png)

This reference covers commands shipped by the host packages in this repository: Core, Admin, and Frontend. Optional packages add their own commands; check their README or run `php artisan list capell` in the target app.

## Install And Upgrade

### `capell:install`

Runs the main install workflow. It can seed default data, run extension install workflows, configure a theme, create users, clear caches, generate sitemaps when a sitemap command is available, and install developer tooling.

```bash
php artisan capell:install
php artisan capell:install --demo --url=https://example.test --user=admin@example.com
php artisan capell:install --package-mode=custom --packages=capell-app/blog,capell-app/navigation
php artisan capell:install --profile=equidynamics --url=https://equidynamics.test --user=admin@example.com
```

For a non-interactive local or CI install that runs every Composer-installed Capell package and installs AI / Agent Bridge developer tooling:

```bash
php artisan capell:install \
  --no-interaction \
  --url="${APP_URL}" \
  --user=admin@example.com \
  --all-packages \
  --developer-tooling
```

Use `--no-boost-install` when you only want Composer to install Laravel Boost and Capell Agent Bridge without regenerating local Boost guidelines, skills, or MCP files:

```bash
php artisan capell:install \
  --no-interaction \
  --url="${APP_URL}" \
  --user=admin@example.com \
  --all-packages \
  --developer-tooling \
  --no-boost-install
```

In non-interactive mode, always pass `--url`. If the app already has users, pass `--user` with an existing user ID or email. If the app has no users yet, pass `--name`, `--email`, and `--password` instead.

| Option                                 | Use it for                                                                      |
| -------------------------------------- | ------------------------------------------------------------------------------- |
| `--demo`                               | Seed demo content during install                                                |
| `--plan`                               | Print the install plan and exit without mutation                                |
| `--fresh` / `--fresh=force`            | Refresh the database before installing; `force` skips confirmation              |
| `--profile=`                           | Apply package, theme, demo, language, and site defaults from an install profile |
| `--package-mode=core\|all\|custom`     | Choose how extension packages are selected                                      |
| `--packages=`                          | Comma-separated package names for custom installs                               |
| `--all-packages`                       | Install all composer-installed Capell packages                                  |
| `--theme=`                             | Activate a theme key when themes are available                                  |
| `--remove-installer`                   | Remove `capell-app/installer` after a successful install                        |
| `--languages=`                         | Comma-separated demo language codes                                             |
| `--sites=`                             | Comma-separated demo site names                                                 |
| `--seed`                               | Run the application database seeder after installing                            |
| `--no-seed-default-data`               | Skip default site, language, content type, and page setup                       |
| `--url=`                               | Site URL, defaulting to `APP_URL`                                               |
| `--user=`                              | Existing user email or ID used as default author                                |
| `--name=` / `--email=` / `--password=` | First user details when the installer creates a user                            |
| `--role-users`                         | Create example users for common admin roles                                     |
| `--role-user-password=`                | Password for example role users                                                 |
| `--no-side-effects`                    | Show the install flow without running real side effects                         |
| `--clear-cache`                        | Clear caches without prompting                                                  |
| `--generate-sitemap`                   | Run sitemap generation when the command exists                                  |
| `--install-welcome-route`              | Remove the Laravel welcome home route when present                              |
| `--developer-tooling`                  | Install Laravel Boost and Capell Agent Bridge developer tooling                 |
| `--no-boost-install`                   | Install developer tooling packages without running `boost:install`              |
| `--production`                         | Run unattended, force no interaction, and refuse `--fresh`                      |

Install profiles can live in `config/capell-install-profiles.php`,
`config('capell.install_profiles')`, or `capell-install-profiles.json`. Explicit
CLI options always win over profile defaults.

```php
return [
    'equidynamics' => [
        'packages' => ['capell-app/bookings', 'capell-app/seo-suite'],
        'theme' => 'equidynamics',
        'demo' => true,
        'languages' => ['en'],
        'sites' => ['Equidynamics'],
    ],
];
```

### `capell:upgrade`

Runs the host upgrade flow: migration phase, registered upgrade steps, cache cleanup, and upgrade ledger checks.

```bash
php artisan capell:upgrade
php artisan capell:upgrade --dry-run
php artisan capell:upgrade --only-steps --force-step=2026_05_01_example
```

| Option              | Use it for                                                      |
| ------------------- | --------------------------------------------------------------- |
| `--dry-run`         | Show the plan without making changes                            |
| `--force`           | Skip interactive confirmations                                  |
| `--force-downgrade` | Continue when Composer reports an older version than the ledger |
| `--no-clear-cache`  | Skip cache clearing after upgrade                               |
| `--caches=`         | Comma-separated cache list: `all`, `page`, `config`, `views`    |
| `--skip-migrations` | Skip migration publishing and running                           |
| `--skip-steps`      | Skip registered upgrade steps                                   |
| `--only-migrations` | Run only the migration phase                                    |
| `--only-steps`      | Run only registered upgrade steps                               |
| `--force-step=*`    | Re-run a specific step ID                                       |

### `capell:rollback`

Rolls back a recorded upgrade step.

```bash
php artisan capell:rollback --step=2026_05_01_example --dry-run
php artisan capell:rollback --step=2026_05_01_example --force
```

Use `--dry-run` before a real rollback. Use `--force` only when the impact is already understood.

## Extension Lifecycle

### `capell:make-theme`

Scaffolds a Capell theme package. Use `--local` for app-owned client themes that
should register runtime views during fresh installs before package install state
exists.

```bash
php artisan capell:make-theme equidynamics \
  --package=app/equidynamics-theme \
  --name=Equidynamics \
  --local
```

| Option       | Use it for                                                         |
| ------------ | ------------------------------------------------------------------ |
| `theme`      | Stable theme key                                                   |
| `--package=` | Composer package name                                              |
| `--name=`    | Human-readable theme name                                          |
| `--path=`    | Parent directory for the package, defaulting to `packages`         |
| `--extends=` | Parent theme key, defaulting to `default`                          |
| `--local`    | Generate local runtime registration without installed-state gating |

### `capell:theme:doctor`

Runs theme-specific diagnostics for a local or installed theme package.

```bash
php artisan capell:theme:doctor equidynamics --path=packages/equidynamics-theme
php artisan capell:theme:doctor equidynamics --json
```

| Option    | Use it for                                           |
| --------- | ---------------------------------------------------- |
| `theme`   | Theme key to inspect                                 |
| `--path=` | Theme package path, defaulting to `packages/{theme}` |
| `--json`  | Machine-readable diagnostic report                   |

### `capell:extension-install`

Runs install workflows for extension packages that are already present in Composer.

```bash
php artisan capell:extension-install
php artisan capell:extension-install capell-app/blog --url=https://example.test --languages=en --sites=Main
php artisan capell:extension-install --all --dry-run
```

| Option                | Use it for                                                                       |
| --------------------- | -------------------------------------------------------------------------------- |
| `extension`           | Package name to install; omit to choose interactively                            |
| `--all`               | Install every extension not already marked installed                             |
| `--include-installed` | Re-run install workflow for an installed extension                               |
| `--dry-run`           | Show the install plan without running commands                                   |
| `--url=`              | Forward a site URL to packages that declare a URL install param                  |
| `--languages=*`       | Forward language values to packages that declare language params                 |
| `--sites=*`           | Forward site values to packages that declare site params                         |
| `--user=`             | Forward a user value to packages that declare a user param                       |
| `--assets=*`          | Forward asset values to packages that declare asset params                       |
| `--param=*`           | Forward dynamic params as `name=value`, `--name=value`, or package-scoped values |

### `capell:extension-uninstall`

Uninstalls extension packages that are already installed in Capell.

```bash
php artisan capell:extension-uninstall
php artisan capell:extension-uninstall capell-app/blog
php artisan capell:extension-uninstall capell-app/blog --delete-data
php artisan capell:extension-uninstall capell-app/blog --delete-package
```

| Option             | Use it for                                                               |
| ------------------ | ------------------------------------------------------------------------ |
| `extension`        | Package name to uninstall; omit to choose interactively                  |
| `--all`            | Uninstall every installed extension                                      |
| `--delete-data`    | Run the extension's optional data deletion hook during uninstall         |
| `--delete-package` | Delete extension data, then remove the Composer package                  |
| `--dry-run`        | Show the uninstall plan without running lifecycle or Composer operations |

### `capell:extensions:repair-composer-drift`

Repairs extension records where Capell and Composer disagree. The Extensions dashboard only reports this drift; it does not run Composer during a page request.

```bash
php artisan capell:extensions:repair-composer-drift vendor/example
php artisan capell:extensions:repair-composer-drift --all
php artisan capell:extensions:repair-composer-drift --all --force
```

| Option    | Use it for                                                                                         |
| --------- | -------------------------------------------------------------------------------------------------- |
| `package` | Repair one Composer-actionable drift record. This explicit repair ignores the all-package gate.    |
| `--all`   | Repair all Composer-actionable drift records when `CAPELL_EXTENSIONS_COMPOSER_DRIFT_AUTO_FIX=true` |
| `--force` | Allow `--all` repair even when `CAPELL_EXTENSIONS_COMPOSER_DRIFT_AUTO_FIX=false`                   |

The command stores the latest repair attempt in `capell_extensions.metadata` using:

| Metadata key                              | Meaning                                      |
| ----------------------------------------- | -------------------------------------------- |
| `composer_drift_last_detected_reason`     | Last drift reason detected for the extension |
| `composer_drift_last_repair_attempted_at` | Timestamp of the latest repair attempt       |
| `composer_drift_last_repair_status`       | `success`, `failed`, or `skipped`            |
| `composer_drift_last_repair_message`      | Operator-facing result or failure message    |

Composer repair is only safe for records where the registry manifest exists and Composer availability or version state is wrong. A stale database record with no current registry manifest, or an extension marked disabled/failed while Composer is present, should be reviewed manually instead of blindly requiring the package.

### `marketplace:qa:extensions-lifecycle`

Runs local Marketplace lifecycle QA for catalogue extensions. Use `--dry-run` first to confirm scope before running installs.

```bash
php artisan marketplace:qa:extensions-lifecycle --dry-run
php artisan marketplace:qa:extensions-lifecycle --dry-run --json
php artisan marketplace:qa:extensions-lifecycle --only=vendor/package
php artisan marketplace:qa:extensions-lifecycle --skip-delete --stop-on-failure
```

| Option              | Use it for                                                          |
| ------------------- | ------------------------------------------------------------------- |
| `--json`            | Emit a compact JSON report for CI/local automation                  |
| `--only=`           | Limit the run to one Composer package                               |
| `--skip-delete`     | Uninstall packages without deleting extension-owned data            |
| `--stop-on-failure` | Stop after the first failed install, uninstall, or delete operation |
| `--dry-run`         | Resolve catalogue records and print the plan without changing state |

The report includes extension name, Composer package, install result, uninstall result, delete result, and failure reason. Non-dry runs queue and execute the Marketplace install attempt locally, then uninstall the installed package and delete extension-owned data unless `--skip-delete` is set.

### `capell:extension-audit`

Validates a package directory, `capell.json`, or package directory collection against Capell extension contracts.

```bash
php artisan capell:extension-audit packages/example
php artisan capell:extension-audit packages/example/capell.json
```

### `capell:extension-playground`

Inspects an extension package or manifest without installing it.

```bash
php artisan capell:extension-playground capell-app/blog --path=packages
```

### `capell:make-extension`

Scaffolds a local Capell package and manifest. In interactive mode, the command asks for any missing package name, profile, target directory, and display name. In non-interactive scripts, pass `package`, `--profile`, and `--path`.

```bash
php artisan capell:make-extension
php artisan capell:make-extension vendor/example --profile=minimal --path=packages --name="Example Extension"
php artisan capell:make-extension vendor/example-tools --profile=full --path=packages --premium
```

## Developer Makers

### `capell:make`

Lists or runs makers registered in `MakerRegistry`.

```bash
php artisan capell:make --dry-run
php artisan capell:make core.action --name=PublishPage --dry-run
```

| Option       | Use it for                                              |
| ------------ | ------------------------------------------------------- |
| `maker`      | Registered maker key                                    |
| `--name=`    | Primary generated name                                  |
| `--type=`    | Optional type, schema, or component type                |
| `--source=`  | Optional source schema/component key                    |
| `--livewire` | Generate Livewire files when the maker supports it      |
| `--database` | Allow database writes when the environment permits them |
| `--dry-run`  | Preview without writing                                 |
| `--force`    | Overwrite existing files after warning                  |

### Legacy maker wrappers

These commands still exist for scripts and muscle memory:

```bash
php artisan capell:make-action CreatePage --data
php artisan capell:make-data PageAuthoringInput
php artisan capell:make-extender HeroFields AfterTitle
php artisan capell:make-schema LandingPage
php artisan capell:make-blueprint LandingPage
```

Prefer `capell:make` for new docs and examples because it shows registered makers and supports dry runs.

## Package Cache And Published Files

### Package cache

```bash
php artisan capell:package-cache
php artisan capell:package-cache:clear
```

Use these after changing installed package metadata, manifests, or local Composer path repositories.

### Components

```bash
php artisan capell:publish-components
php artisan capell:cache-components
php artisan capell:clear-components-cache
```

Publishing components is an override path. Prefer package extension points when you only need to add behaviour.

### Migrations

```bash
php artisan capell:publish-migrations
php artisan capell:publish-migrations --type=settings
php artisan capell:publish-migrations --items=create_sites_table --path=database/migrations
php artisan capell:delete-migrations --all
php artisan capell:delete-migrations vendor/example
```

Use package install commands for normal installs. Reach for direct migration publishing when building packages or debugging install flow.
`capell:delete-migrations` removes published schema migration files whose basenames match registered package `database/migrations` directories. Extension uninstall runs the same cleanup for the selected extension automatically.

## Admin Commands

### `capell:admin-install`

Installs Admin package requirements and can integrate Capell into a Filament panel.

```bash
php artisan capell:admin-install --admin-panel-changes=auto --panel=admin
php artisan capell:admin-install --admin-panel-changes=manual
```

### `capell:admin-setup`

Runs admin setup and Filament panel integration.

```bash
php artisan capell:admin-setup --panel=admin --configurators=auto
php artisan capell:admin-setup --integration-only --preview
```

Common options:

- `--url=`, `--user=`, `--languages=`, `--sites=`, and `--theme=` seed initial admin context.
- `--integration-only` edits the panel without running the wider setup.
- `--skip-panel-integration` leaves the Filament panel untouched.
- `--configurators=auto` discovers configurators automatically.
- `--no-colors`, `--no-widgets`, and `--no-navigation` skip those panel changes.
- `--skip-permission-sync` skips the permission sync that normally follows admin install.
- `--preview` shows proposed file changes without writing.
- `--force` skips confirmation prompts.

See [Admin setup](../admin/setup.md) for the panel integration flow.

### Admin cache and publishing

```bash
php artisan capell:admin-clear-cache
php artisan capell:admin-cache-widgets
php artisan capell:admin-clear-widgets-cache
php artisan capell:admin-cache-configurators
php artisan capell:admin-clear-configurators-cache
php artisan capell:admin-publish-resources --type=page --force
```

`capell:admin-publish-resources` is an advanced escape hatch. Most package work should use resources, extenders, configurators, bridges, and settings schemas instead of publishing host resources.

### Admin upgrades

```bash
php artisan capell:admin-upgrade
php artisan capell:admin-upgrade-summary-email
```

Upgrade summary email recipients come from `CAPELL_UPDATE_NOTIFICATION_EMAILS`.

## Frontend Commands

```bash
php artisan capell:frontend-install
php artisan capell:frontend-install --dev
php artisan capell:frontend-after-install
php artisan capell:frontend-after-install --dev
php artisan capell:frontend-upgrade
```

`--dev` runs the Vite development build path instead of the production build where supported.

## Demo Helpers

```bash
php artisan capell:faker --count=25 --packages --sites=Main --languages=en --force
php artisan capell:core-faker --count=10 --sites=Main --languages=en
```

These create local content for development and testing. Do not run them against production data.

## Optional-Package Commands

Commands such as static-site generation, sitemap generation, package-specific demo commands, and frontend Tailwind aggregation are provided by optional packages in a consuming app. Keep those commands in the package README or package docs that own them.

| Command                           | Package                     |
| --------------------------------- | --------------------------- |
| `capell:static-site`              | `capell-app/html-cache`     |
| `capell:xml-sitemap`              | `capell-app/site-discovery` |
| `capell:frontend-tailwind-assets` | `capell-app/frontend`       |
| `capell:admin-demo`               | `capell-app/demo-kit`       |
| `capell:demo-kit-full-demo`       | `capell-app/demo-kit`       |

The source of truth in any app is:

```bash
php artisan list capell
```

## Further Reading

- [Install guide](../getting-started/install.md)
- [Configuration reference](configuration.md)
- [Admin setup](../admin/setup.md)
- [Creating Capell packages](../packages/README.md)
- [Approved packages](../packages/catalog.md)
