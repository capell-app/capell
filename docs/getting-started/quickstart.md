# Capell quickstart

This guide gets a fresh Laravel app running with Capell demo content. Use it when you want to see the admin, pages, media, and frontend flow before wiring Capell into a real project.

For existing Laravel apps, use the [install guide](install.md#path-b-existing-laravel-app).

## Before you start

| Tool     | Version                                                              |
| -------- | -------------------------------------------------------------------- |
| PHP      | 8.4+                                                                 |
| Laravel  | 12.41.1+ or 13.x                                                     |
| Filament | 5.6.8+ below 5.7 (`^5.6.8 <5.7.0-beta`)                              |
| Node.js  | 20+                                                                  |
| Composer | 2.7+                                                                 |
| Database | MySQL 8+, MariaDB 10.3+, SQLite, or your configured Laravel database |

Required PHP extensions: `fileinfo`, `intl`, `mbstring`, `openssl`, `curl`, `simplexml`, and either `gd` or `imagick`.

## 1. Create the Laravel app

```bash
composer create-project laravel/laravel music-store
cd music-store
cp .env.example .env
php artisan key:generate
```

Set your database values in `.env`. For a fast local trial, SQLite is fine:

```bash
touch database/database.sqlite
```

Then set:

```env
DB_CONNECTION=sqlite
QUEUE_CONNECTION=sync
APP_URL=http://localhost:8000
```

## 2. Install Capell

Capell is installed into your Laravel application through Composer. The current 1.x foundation release is available through public Packagist packages; Marketplace access may
be required separately for commercial extensions or customer services. Require
the installer first: it pulls in core, and `capell:install` adds the admin and
frontend packages:

```bash
composer require capell-app/installer
php artisan filament:install --panels
php artisan capell:install --demo --url=http://localhost:8000
```

During the installer, expect prompts for:

| Prompt           | Good local answer                                     |
| ---------------- | ----------------------------------------------------- |
| Site URL         | `http://localhost:8000`                               |
| Admin user       | Pick an existing user or let the installer create one |
| Clear caches     | Yes                                                   |
| Generate sitemap | Yes for the demo                                      |

## 3. Start the app

```bash
php -S 127.0.0.1:8000 -t public public/index.php
```

Open:

- Admin: `http://localhost:8000/admin`
- Frontend: `http://localhost:8000`

You should see the Capell admin dashboard after login:

![Capell admin dashboard](../images/admin-dashboard.png)

## 4. Check the first page

In the admin, open **Pages**. Demo installs should show a page tree.

![Capell pages list](../images/admin-pages-list.png)

Open a page, change a small piece of text, then click **Save**. Use the preview action from the Pages list to confirm the draft renders. When you are happy with it, click **Publish**.

If `QUEUE_CONNECTION=sync`, publish-side jobs run during the request. If you use `database` or `redis`, keep a worker running:

```bash
php artisan queue:work
```

If the page does not change after publishing, start with the admin **Clear Cache** action or the cache commands in [Create your first page](create-your-first-page.md#preview-and-publish). Then use [Troubleshooting](../operations/troubleshooting.md#published-pages-still-show-old-content) for the shortest stale-page fix, or [Debugging public output](../frontend/debugging-public-output.md) when the rendered frontend HTML, cache headers, or package output look wrong.

## 5. Add one useful package

For the full editor experience, add [ContentSections](../packages/content-sections.md):

```bash
composer require capell-app/content-sections
php artisan migrate
```

ContentSections adds reusable content and widget-based layout building to the admin.

## Agency proof install

Use this short path when you want to prove that a Laravel project can become an
editable, branded agency site without building a custom CMS foundation first:

```bash
composer require capell-app/theme-agency
php artisan capell:theme-agency-demo --url=http://localhost:8000 --force
```

Check three outcomes before treating the proof as complete:

1. The Agency demo homepage renders at `http://localhost:8000`.
2. Its page tree is editable from Filament at `http://localhost:8000/admin`.
3. A saved and published content change appears on the public page without any
   authoring controls or preview state.

The demo is intentionally repeatable: use `--force` when you need to reconcile
the local proof content. See the Theme Agency package documentation before
turning the demo structure into a client-specific build.

## First-run fixes

| Symptom                              | Run this                                                                                               | Read next                                                                                                         |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------- |
| `php artisan` says permission denied | `chmod +x artisan && chmod -R u+rwX storage bootstrap/cache`                                           | [Troubleshooting](../operations/troubleshooting.md#php-artisan-says-permission-denied)                            |
| You log in but pages do not update   | `php artisan queue:work`                                                                               | [Published pages never generate](../operations/troubleshooting.md#published-pages-never-generate)                 |
| The frontend shows old content       | Use admin **Clear Cache** first. If `capell-app/html-cache` is installed, run its clear/warm commands. | [Published pages still show old content](../operations/troubleshooting.md#published-pages-still-show-old-content) |
| Package classes are missing          | `composer dump-autoload && php artisan optimize:clear`                                                 | [Debugging package discovery](../packages/debugging-package-discovery.md)                                         |
| Tailwind misses Capell admin styles  | Add the `@source` lines from [theme compilation](install.md#7-theme-compilation)                       | [Tailwind vendor CSS](../frontend/tailwind-vendor-css.md)                                                         |

## Find the right follow-up guide

Use these links when the quickstart works, but the next symptom needs more context:

| When you see this                     | Go here                                                                                                                                           |
| ------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| A published page is stale or missing  | [Operations troubleshooting](../operations/troubleshooting.md#published-pages-still-show-old-content)                                             |
| Cache is bypassing or unsafe          | [Debugging public output](../frontend/debugging-public-output.md)                                                                                 |
| You need to clear or warm HTML cache  | [Frontend guide](../frontend/guide.md#html-cache-behaviour)                                                                                       |
| A queue-backed job is not completing  | [Site Health](../operations/site-health.md) and [published pages never generate](../operations/troubleshooting.md#published-pages-never-generate) |
| Package output is missing from a page | [Extension troubleshooting](../packages/extension-troubleshooting.md)                                                                             |
| You need the command owner            | [Capell CLI command index](../development/commands.md)                                                                                            |

## Next

- [Install guide](install.md) for a real app.
- [Capell Learn](capell-learn.md) for the shortest concept path.
- [How Capell works](how-capell-works.md) for the model and extension points.
- [Music store CMS example](../examples/music-store-cms.md) for a realistic content model.
- [Admin](../admin/index.md) for page editing and admin extension points.
- [Operations](../operations/index.md) for cache, queue, deploy, and first-run fixes.
