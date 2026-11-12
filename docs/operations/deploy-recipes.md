# Deploy recipes

Use one order for every routine deploy. The commands were run on a disposable application. The host recipes below were not run on those hosts, so check the result with `capell:doctor` on yours. Back up the database first.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan capell:upgrade --force --no-interaction
php artisan capell:runtime-refresh --no-interaction
```

Then reload PHP-FPM, and [refresh public HTML](deploy-and-cache-refresh.md#refresh-public-html-in-the-right-order) if HTML Cache is installed.

`capell:upgrade` runs migrations and upgrade steps. `capell:runtime-refresh` rebuilds caches, warms pages, runs Doctor and restarts queues. Run `capell:upgrade --dry-run` first when you want to review the plan.

Capell records the content of `vendor/composer/installed.json` with each runtime cache and rebuilds any cache whose recorded install set no longer matches, so a cache written before the last `composer install` is not trusted. Installing with `--no-dev` over a tree that had development packages therefore does not need the caches deleted by hand, provided PHP-FPM is reloaded after the install. `capell:runtime-refresh` still regenerates the full runtime caches.

## Deployer

The task order was checked against Deployer 8 and its `laravel` recipe. No deploy was run. Share `storage` and `.env` between releases, then add these tasks, which run before `deploy:symlink`:

```php
task('capell:upgrade', fn () => run('cd {{release_path}} && {{bin/php}} artisan capell:upgrade --force --no-interaction'));
task('capell:refresh', fn () => run('cd {{release_path}} && {{bin/php}} artisan capell:runtime-refresh --no-interaction'));
task('artisan:migrate', fn () => null);

before('deploy:symlink', 'capell:upgrade');
after('capell:upgrade', 'capell:refresh');
```

The `laravel` recipe would run `artisan:migrate` before `capell:upgrade`, so the empty `artisan:migrate` task above leaves migrations to `capell:upgrade`. Reload PHP-FPM after `deploy:symlink`.

## Forge and Ploi

Open the site's deploy script. Keep the lines your host generated to enter the site directory and pull the branch, and keep its PHP-FPM reload line at the end. Replace the host's Composer, migrate and cache lines with these, using the host's PHP and Composer variables if your script has them:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan capell:upgrade --force --no-interaction
php artisan capell:runtime-refresh --no-interaction
```

Do not leave the host's own `php artisan migrate --force` or `php artisan optimize` lines after these.

## Check the deploy

```bash
php artisan capell:doctor
```

## Related guides

- [Deploys and cache refresh](deploy-and-cache-refresh.md)
- [Upgrading Capell](upgrading.md)
