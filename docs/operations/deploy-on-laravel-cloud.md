# Deploy Capell on Laravel Cloud

This is the manual recipe for running Capell on your own [Laravel Cloud](https://cloud.laravel.com) application. It makes one set of choices and explains each one. If you want Capell to prepare the Cloud application for you, use the connect flow on capell.app instead; this page describes what that flow sets up, so you can check it or do it by hand.

Read [Going live](going-live.md) as well. Everything there about domains, TLS and the queue and scheduler still applies; this page replaces only the parts Laravel Cloud runs for you.

## How each statement on this page is verified

Each section says how its statements were checked:

- **Verified in Capell** means the behaviour was checked by running Capell's own tests or commands, or by reading Capell's source where the section says so.
- **From the Laravel Cloud documentation** means the statement comes from the linked Laravel Cloud page.
- **Not yet verified on a live Cloud project** means nobody has yet run this step end to end on a real Laravel Cloud application. Treat those steps as the expected path. Capell will remove this label once a timed deployment has been run against this recipe.

## What you need

- A Laravel Cloud account and an organisation you can create applications in.
- A GitHub, GitLab or Bitbucket repository for the site.
- PHP 8.4 and Node 24 on the application. These are the versions the capell.app connect flow selects.

## Choices this recipe makes

| Concern | Choice | Why |
| --- | --- | --- |
| Release root | `CAPELL_RELEASE_ROOT_MODE=immutable` | The Cloud filesystem is reset on every deploy and is not shared between replicas. |
| Database | Laravel MySQL | Cloud does not support SQLite, and production Capell sites already run on MySQL-compatible databases. |
| Cache | Laravel Valkey | Capell's upgrade lock relies on `Cache::lock`, which must be shared between replicas. |
| Queue | Laravel Cloud managed queue | Cloud's preferred queue. It needs no worker process to supervise. |
| Scheduler | The App cluster's Scheduler option | Cloud runs `schedule:run` every minute. |
| Uploads | Laravel Object Storage bucket as the default disk | Local files are lost on the next deploy. |
| Runtime | PHP-FPM | Capell also supports Octane, but this recipe keeps to the default runtime. |

## Prepare the repository

Install Capell in a local clone first, and commit the result. On an immutable release Capell refuses to publish migrations or run Composer at runtime, so everything the installer writes into the application must already be in the repository when Cloud builds it.

Follow [Install Capell](../getting-started/install.md) locally against a local database:

```bash
composer create-project laravel/laravel capell-site
cd capell-site
composer require capell-app/installer
php artisan capell:install
```

Then commit the application, including `bootstrap/providers.php`, `app/Providers/Filament/`, `config/`, `database/migrations/`, `composer.lock` and `package-lock.json`, and push it to your repository.

Install every extension you want on the site the same way, locally with `composer require`, before you deploy. The admin cannot install extensions on an immutable release.

**Verified in Capell:** the release-root guard refuses runtime Composer and migration publication when the mode is not `mutable` (`ReleaseRootWriteGuardTest`, `ComposerPackageActionReleaseRootGuardTest`).

## Create the Cloud application

Create an application from the repository in Laravel Cloud, then add these resources to its environment:

1. A **Laravel MySQL** database. It must be in the same region as the environment.
2. A **Laravel Valkey** cache.
3. A **Laravel Object Storage** bucket, set as the default disk. Install `league/flysystem-aws-s3-v3` in the repository first.
4. A **managed queue**.

Laravel Cloud adds the connection details for each resource to the environment, including the `DB_*` values, `CACHE_STORE`, `FILESYSTEM_DISK` for the default bucket and `QUEUE_CONNECTION=cloud`. Redeploy after you attach a resource.

**From the Laravel Cloud documentation:** [Environments](https://laravel.com/cloud/docs/environments), [Laravel MySQL](https://laravel.com/cloud/docs/resources/databases/laravel-mysql), [Valkey](https://laravel.com/cloud/docs/resources/caches/valkey), [Object Storage](https://laravel.com/cloud/docs/resources/object-storage), [Queues](https://laravel.com/cloud/docs/queues).

**Not yet verified on a live Cloud project:** Capell running on each of these resources.

## Set the environment variables

Add these to the environment, alongside what Cloud adds for you:

```dotenv
APP_ENV=production
APP_DEBUG=false
CAPELL_RELEASE_ROOT_MODE=immutable
SESSION_DRIVER=database
```

`APP_URL` is set by Laravel Cloud. Set it yourself once you add a custom domain, so that Capell builds links with the public address.

Do not set `CAPELL_INSTALL_MODE=cloud` in a manual deployment. It tells Capell the site was prepared by the capell.app connect flow, and the `capell:cloud-bootstrap` deploy command then expects a registration URL and token that only that flow provides.

**Verified in Capell:** inside cloud install mode, `capell:cloud-bootstrap` fails without `CAPELL_CLOUD_REGISTRATION_URL` and `CAPELL_REGISTRATION_TOKEN` (`CloudBootstrapCommandTest`). Outside cloud install mode it exits without doing anything (source review of `CloudBootstrapCommand`).

## What `immutable` turns off

With `CAPELL_RELEASE_ROOT_MODE=immutable`:

- Installing, updating or removing extensions from the admin is refused. Change `composer.json` locally and deploy instead.
- Migration publication at runtime is refused. Publish migrations locally and commit them.
- Rebuilding frontend assets from the admin is refused. The build commands below build them.

Content, media uploads, settings and everything else that lives in the database or the storage bucket work normally.

**Verified in Capell:** the guard's refusal message names `CAPELL_RELEASE_ROOT_MODE` and tells the operator to make the change while building the next release (`ReleaseRootWriteGuardTest`).

## Build commands

Put one command on each line:

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci --no-audit --no-fund
npm run build
php artisan optimize
```

Laravel Cloud keeps filesystem changes made during the build, and recommends running `php artisan optimize` there. It discards filesystem changes made by deploy commands.

**From the Laravel Cloud documentation:** [Environments: build and deploy commands](https://laravel.com/cloud/docs/environments).

**Not yet verified on a live Cloud project:** whether `php artisan optimize` completes during the build for a Capell application. The capell.app connect flow currently runs it as a deploy command instead, because a cache it writes needs the database. If the build fails at this line, move it to the end of the deploy commands and report it, so this recipe can be corrected.

## Deploy commands

```bash
php artisan migrate --force
```

Do not add `queue:restart`, `optimize:clear` or `storage:link`. Laravel Cloud restarts workers after each deploy and advises against all three.

**From the Laravel Cloud documentation:** [Environments: deploy commands](https://laravel.com/cloud/docs/environments).

## Create the site and the first administrator

The repository already contains Capell's code and migrations, but the Cloud database is empty after the first deploy. Run the installer once from Laravel Cloud's command runner to create the site and the first administrator:

```bash
php artisan capell:install --no-interaction --package-mode=core --theme=foundation --clear-cache --install-welcome-route --url=https://your-domain.example --name="Your Name" --email=you@example.com --password=change-me
```

Use the same `--package-mode` and `--theme` you chose when you installed locally. `--install-welcome-route` removes Laravel's default welcome route so Capell serves the home page.

Sign in, then change the password straight away.

**Not yet verified on a live Cloud project:** this step. The live proof run must confirm that the installer finishes on an immutable release whose code and migrations are already committed.

## Queue worker

The managed queue needs no worker configuration. Capell's jobs run on it, apart from Marketplace operations.

Marketplace operations use their own queue. On an immutable release they are refused anyway, because they run Composer.

If you use a worker cluster instead of a managed queue, run `php artisan queue:work` on it and set `QUEUE_CONNECTION` to match.

**From the Laravel Cloud documentation:** [Queues](https://laravel.com/cloud/docs/queues), [Workers](https://laravel.com/cloud/docs/workers).

## Scheduler

Turn on **Scheduler** for the App cluster and redeploy. Laravel Cloud then runs `php artisan schedule:run` every minute.

Capell schedules work every minute, including the public-read reconciliation, and records a scheduler heartbeat every five minutes. On a Flex cluster with scale to zero, these scheduled tasks wake the environment, so it will not sleep while the scheduler is on.

**From the Laravel Cloud documentation:** [Scheduled tasks](https://laravel.com/cloud/docs/scheduled-tasks), [Compute: scale to zero](https://laravel.com/cloud/docs/compute#scale-to-zero).

**Verified in Capell:** the every-minute public-read reconciliation and the five-minute heartbeat are registered by Core's service provider. The heartbeat is covered by `HostingPlatformCheckTest`.

## Storage

Set the bucket as the default disk, so `FILESYSTEM_DISK` points at it and media uploads persist across deploys. Buckets are public or private as a whole, so use a public bucket for media that visitors load directly.

**From the Laravel Cloud documentation:** [Object Storage](https://laravel.com/cloud/docs/resources/object-storage).

**Not yet verified on a live Cloud project:** Capell media uploads and image conversions on a Cloud bucket.

## Domains

Each environment gets a `laravel.cloud` address after its first successful deploy. To use your own domain, add it in the environment's network settings, create the DNS records Cloud shows you, and wait until it shows as connected. Cloud issues and renews the certificate.

Then set `APP_URL` to the new address, and add the domain to the site in the Capell admin under **Sites**, because Capell resolves each request to a site by its domain.

**From the Laravel Cloud documentation:** [Domains](https://laravel.com/cloud/docs/domains).

## Octane

Capell supports Octane, and Laravel Cloud can run Octane on FrankenPHP. Capell does not refuse to run under Octane. This recipe uses PHP-FPM, because nobody has yet run Capell under Octane on Laravel Cloud. See [Octane](octane.md) before you switch.

**Verified in Capell:** Core registers its Octane state reset and contains no check that refuses Octane (source review).

## After each deploy

1. Open the site's public address and one page you know exists.
2. Sign in to the admin.
3. Upload an image, then redeploy and confirm the image still loads.
4. Run `php artisan capell:doctor` from the command runner.

`capell:doctor` includes a hosting check, `core.hosting.platform`, which reports the platform Capell detected and the release-root mode. It warns when:

- the release root is `mutable` on Laravel Cloud;
- `QUEUE_CONNECTION` is `sync` in production;
- the scheduler has not recorded a heartbeat in the last 15 minutes.

The heartbeat warning is expected for the first five minutes after you turn the scheduler on.

**Verified in Capell:** the hosting check detects Laravel Cloud from the `LARAVEL_CLOUD` variable that the framework's `laravel_cloud()` helper reads, and reports each hazard above (`HostingPlatformCheckTest`).
