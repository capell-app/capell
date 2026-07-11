# Capell Artisan Commands Reference

## Core Commands (`capell:*`)

| Command                         | Description                                                      |
| ------------------------------- | ---------------------------------------------------------------- |
| `capell:install`                | Full Capell installation wizard                                  |
| `capell:upgrade`                | Upgrade Capell to latest version                                 |
| `capell:static-site`            | Pre-generate static HTML cache for pages (capell-app/html-cache) |
| `capell:doctor`                 | Check install health                                             |
| `capell:cache-components`       | Cache component definitions                                      |
| `capell:clear-components-cache` | Clear cached component definitions                               |
| `capell:publish-components`     | Publish Blade components to app                                  |
| `capell:publish-migrations`     | Publish migration files to app                                   |

## Admin Commands (`capell:admin-*`)

| Command                | Description                                             |
| ---------------------- | ------------------------------------------------------- |
| `capell:admin-install` | Install admin panel (runs migrations, publishes assets) |
| `capell:admin-setup`   | Interactive setup wizard for admin                      |
| `capell:admin-upgrade` | Upgrade admin panel                                     |

| `capell:admin-cache-schemas` | Cache all schema definitions for performance |
| `capell:admin-clear-schemas-cache` | Clear cached schemas (use after schema changes) |
| `capell:admin-make-schema {type?} {name?}` | Scaffold a new schema class |
| `capell:admin-publish-schema` | Publish schema files; prefer extenders for upgrade safety |
| `capell:admin-publish-resources` | Publish Filament resources to app |
| `capell:admin-view-page-cache` | View list of cached HTML pages |

## Frontend Commands (`capell:frontend-*`)

| Command                           | Description                                  |
| --------------------------------- | -------------------------------------------- |
| `capell:frontend-install`         | Install frontend package                     |
| `capell:frontend-after-install`   | Post-install hook (called by install)        |
| `capell:frontend-site-check`      | Verify site configuration is correct         |
| `capell:frontend-tailwind-assets` | Regenerate aggregated TailwindCSS asset file |
| `capell:frontend-upgrade`         | Upgrade frontend package                     |

## Add-on Package Commands

### Starter Sites Package

| Command             | Description                                     |
| ------------------- | ----------------------------------------------- |
| `capell:demo`       | Seed example site content, media, and languages |
| `capell:admin-demo` | Seed admin-facing example site data             |

### Address Package

| Command                  | Description                                 |
| ------------------------ | ------------------------------------------- |
| `capell:address-install` | Install address package (migrations, seeds) |
| `capell:address-demo`    | Seed address example data                   |

### SEO Suite Package

| Command                         | Description                 |
| ------------------------------- | --------------------------- |
| `capell:seo-suite-install`      | Install SEO Suite package   |
| `capell:seo-suite-setup`        | Generate sitemap setup data |
| `capell:admin-test-openai`      | Test OpenAI connectivity    |
| `capell:admin-monitor-ai-usage` | Display AI usage statistics |
| `capell:admin-clear-ai-cache`   | Clear AI response cache     |

### Blog Package

| Command                    | Description                                  |
| -------------------------- | -------------------------------------------- |
| `capell:blog-install`      | Install blog package                         |
| `capell:blog-setup`        | Configure blog (create blueprints, settings) |
| `capell:blog-create-pages` | Create default Blog and Archive pages        |
| `capell:blog-demo`         | Seed demo blog articles                      |

### Hero Package

| Command             | Description            |
| ------------------- | ---------------------- |
| `capell:hero-setup` | Configure hero widgets |
| `capell:hero-demo`  | Seed hero example data |

## Common Development Workflows

### After changing schema/type files:

```bash
php artisan capell:admin-clear-schemas-cache
php artisan capell:admin-cache-schemas  # optional, for performance
```

### After modifying content that affects frontend cache:

```bash
php artisan capell:admin-clear-cache
```

### After modifying CSS/Tailwind sources:

```bash
php artisan capell:frontend-tailwind-assets
npm run build  # or dev
```

### Full fresh install:

```bash
php artisan migrate:fresh --seed
php artisan capell:install
php artisan capell:admin-install
php artisan capell:frontend-install
php artisan capell:install --demo  # optional
```

### Fresh demo install verification:

Use the single installer entry point for a full fresh demo setup:

```bash
php artisan capell:install --fresh=force --demo
```

`--fresh` uses a Laravel Prompts confirmation with a default of "no". In a
non-TTY command run it exits with `Fresh install cancelled.` unless the prompt is
answered. Use `--fresh=force` when Codex or another agent needs to prove the
fresh install path end to end without an interactive confirmation.

For prompt-free setup, pass the options that answer required prompts:

```bash
php artisan capell:install --fresh=force --demo \
  --url=http://127.0.0.1:8000 \
  --package-mode=all \
  --theme=default \
  --name="Capell Admin" \
  --email=codex-dashboard-qa@example.com \
  --password=password \
  --clear-cache \
  --install-welcome-route
```

Relevant installer prompt options:

| Prompt / decision              | Option to supply                                                                 |
| ------------------------------ | -------------------------------------------------------------------------------- |
| Fresh destructive confirmation | `--fresh=force`                                                                  |
| Install demo data?             | `--demo` preselects demo mode; interactive runs may still confirm, default `yes` |
| Site URL                       | `--url=<url>`                                                                    |
| Package selection              | `--package-mode=core`, `--package-mode=all`, or `--packages=vendor/package,...`  |
| Theme selection                | `--theme=<theme-key>`; use `default` for the built-in starter theme              |
| First admin user               | Pass `--name`, `--email`, and `--password` together                              |
| Example role users             | `--role-users --role-user-password=<password>`                                   |
| Application database seeder    | `--seed` runs Laravel's `db:seed` after install steps                            |
| Cache clearing                 | `--clear-cache`                                                                  |
| Replace Laravel welcome route  | `--install-welcome-route`                                                        |
| Developer tooling              | `--developer-tooling`; add `--no-boost-install` to skip `boost:install`          |

`capell:install` seeds core setup data, including the default site, languages,
types, theme, and pages, by default. Pass `--no-seed-default-data` to skip
package setup data. Pass `--seed` when the host application's `DatabaseSeeder`
should also run after the Capell install plan. Pass `--demo` to preselect available packages whose
`capell.json` declares `"demo": true` and run selected package demo commands
after setup data has been seeded. Interactive demo installs ask for confirmation
before changing the install plan.

### Check if everything is configured:

```bash
php artisan capell:frontend-site-check
```

### Pre-generate all pages for production:

```bash
php artisan capell:static-site
php artisan capell:xml-sitemap
```

## Standard Laravel Commands (frequently used with Capell)

```bash
# Migrations
php artisan migrate
php artisan migrate:fresh --seed
php artisan make:migration create_my_table

# Caching (Laravel)
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan optimize:clear  # clears all

# Queue (for async cache generation)
php artisan queue:work
php artisan queue:restart

# Tinker (inspect models)
php artisan tinker
```
