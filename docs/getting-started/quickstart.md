# Capell quickstart

Use this path to evaluate the current 1.x foundation in a fresh Laravel application. It installs Core, Admin, Frontend, Installer, and Marketplace, creates a site and administrator, seeds demo content, generates frontend assets, and runs the install health summary.

The current 1.x Capell Foundation release is MIT-licensed and available through public Packagist packages without a Capell account. Paid marketplace packages use separate commercial terms and entitlement-scoped Composer access.

For an existing application, use the [Install guide](install.md#existing-laravel-applications) and take a database and media backup before running migrations.

## Before you start

Allow about ten minutes for the first Composer install.

| Requirement | Supported value                                                         |
| ----------- | ----------------------------------------------------------------------- |
| PHP         | 8.4+                                                                    |
| Laravel     | 12.41.1+ or 13.x                                                        |
| Filament    | Installed by the selected Capell Admin package; supported line `~5.6.8` |
| Node.js     | 20+                                                                     |
| Composer    | 2.7+                                                                    |
| Database    | MySQL 8+, MariaDB 10.3+, SQLite, or the configured Laravel database     |

Required PHP extensions: `fileinfo`, `intl`, `mbstring`, `openssl`, `curl`, `simplexml`, and either `gd` or `imagick`.

Capell renders through this Laravel application. This quickstart does not create a hosted Capell account or a public content-delivery API.

## 1. Create the Laravel application

```bash
composer create-project laravel/laravel capell-site
cd capell-site
cp .env.example .env
php artisan key:generate
```

Configure `APP_URL` and a database in `.env`. SQLite is enough for a disposable local evaluation:

```bash
touch database/database.sqlite
```

```env
APP_URL=http://localhost:8000
DB_CONNECTION=sqlite
QUEUE_CONNECTION=sync
```

## 2. Run the guided installer

Require the public Installer package first. Do not run `filament:install --panels` separately: Admin is one of the packages selected and configured by `capell:install`.

```bash
composer require capell-app/installer
php artisan capell:install --demo --url=http://localhost:8000
```

For a normal evaluation, accept the full foundation selection and the default theme. The installer asks for:

| Prompt            | Local evaluation answer                            |
| ----------------- | -------------------------------------------------- |
| Package selection | All foundation packages                            |
| Theme             | Default                                            |
| Site URL          | `http://localhost:8000`                            |
| Site name         | Any recognisable local name                        |
| Administrator     | Create one with an email and strong local password |
| Clear caches      | Yes                                                |
| Welcome route     | Yes for a fresh demo application                   |

The final output is part of the install contract. A healthy run ends in this order:

```text
Capell Install Health Summary
All checks passed.
✓ Installation complete!
Capell Install Handoff
```

If a required package lifecycle, asset build, permission sync, or health check fails, the command exits non-zero and withholds the success message. Follow the printed `Fix:` instruction, then rerun the installer; do not treat a partial run as production-ready.

The handoff names the installed packages, safe Admin and public URLs, first-page state, warnings, and the next verified action. It does not require a Capell account or send a telemetry identity. CI can persist the same redacted result with `--handoff-json=storage/app/capell-install-handoff.json`.

### Reproducible non-interactive smoke command

CI and release verification can use the same public path without prompts:

```bash
php artisan capell:install \
  --fresh=force \
  --demo \
  --package-mode=all \
  --theme=default \
  --seed \
  --url=http://localhost:8000 \
  --name="Capell evaluation" \
  --email=owner@example.test \
  --password='replace-this-local-password' \
  --clear-cache \
  --install-welcome-route \
  --no-interaction
```

This command is destructive because `--fresh=force` rebuilds the database. Use it only in a disposable application or isolated CI database.

## 3. Start the application

```bash
php artisan serve
```

Open:

- `http://localhost:8000/admin` and sign in with the administrator created by the installer;
- `http://localhost:8000` to see the seeded public page.

The Admin package should present a styled Pages workspace—not an unstyled Laravel or Filament shell:

![Capell Pages list with seeded pages and publish status](../images/admin-pages-list.png)

## 4. Publish and recover one change

In **Pages**, open a seeded page, change a short piece of text, and save it. Preview the page, publish the change, then confirm the public URL shows it.

![Capell page editor with content and publishing controls](../images/generated/admin/admin-page-edit-form.png)

Open the page's history relation after the save. Inspect the before/after change, preview a rollback, and cancel it unless you deliberately want to test page-only recovery. Page rollback restores the page and its owned content relationships; it does not restore the application database, media store, analytics counters, or infrastructure.

Continue with [Create your first page](create-your-first-page.md) for the full field-by-field walkthrough.

## 5. Confirm health

```bash
php artisan capell:doctor
```

If you switch from `QUEUE_CONNECTION=sync` to `database` or `redis`, keep a worker running:

```bash
php artisan queue:work
```

Before production, also configure and prove the separate [database and media backup](../operations/backups.md) path. Page history is not disaster recovery.

## First-run fixes

| Symptom                                                | Action                                                                                                          | Read next                                                                                                         |
| ------------------------------------------------------ | --------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| `php artisan` is not executable or writable paths fail | `chmod +x artisan && chmod -R u+rwX storage bootstrap/cache`                                                    | [Permissions troubleshooting](../operations/troubleshooting.md#php-artisan-says-permission-denied)                |
| A queued publish never finishes                        | Start `php artisan queue:work`                                                                                  | [Published pages never generate](../operations/troubleshooting.md#published-pages-never-generate)                 |
| The public page remains stale                          | Use Admin **Clear Cache**, then inspect the response/cache path                                                 | [Published pages still show old content](../operations/troubleshooting.md#published-pages-still-show-old-content) |
| A package class is missing after Composer              | `composer dump-autoload && php artisan optimize:clear`                                                          | [Package discovery](../packages/debugging-package-discovery.md)                                                   |
| Frontend CSS is missing                                | `php artisan capell:frontend-install`, then run the application's normal npm build if the installer requests it | [Themes and frontend assets](install.md#themes-and-frontend-assets)                                                               |
| The installer stops at health review                   | Run the exact `Fix:` command shown, then rerun `php artisan capell:doctor`                                      | [Site Health](../operations/site-health.md)                                                                       |

## Next

- [First editor session](first-session.md)
- [Create your first page](create-your-first-page.md)
- [Theme Library](../admin/theme-library.md)
- [Package catalogue and maturity](../packages/catalog.md)
- [Upgrading and rollback](../operations/upgrading.md)
- [Backups and scratch restores](../operations/backups.md)
