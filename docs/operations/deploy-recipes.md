# Deploy recipes

Use one order for every routine deploy. The commands were run on a disposable application. The host recipes below were not run on those hosts, so check the result with `capell:doctor` on yours. Back up the database first.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan capell:upgrade --force --no-interaction
php artisan capell:runtime-refresh --no-interaction
```

Then reload PHP-FPM, and [refresh public HTML](deploy-and-cache-refresh.md#refresh-public-html-in-the-right-order) if HTML Cache is installed.

`capell:upgrade` runs migrations and upgrade steps. `capell:runtime-refresh` rebuilds caches, warms pages, runs Doctor and restarts queues. Run `capell:upgrade --dry-run` first when you want to review the plan.

## Deployer

Share `storage` and `.env` between releases, then add these tasks after `deploy:vendors` and before `deploy:symlink`:

```php
task('capell:upgrade', fn () => run('cd {{release_path}} && {{bin/php}} artisan capell:upgrade --force --no-interaction'));
task('capell:refresh', fn () => run('cd {{release_path}} && {{bin/php}} artisan capell:runtime-refresh --no-interaction'));

before('deploy:symlink', 'capell:upgrade');
after('capell:upgrade', 'capell:refresh');
```

Reload PHP-FPM after `deploy:symlink`.

## Forge and Ploi

Put this in the site's deploy script. Keep the host's generated PHP-FPM reload line after it:

```bash
cd "$SITE_PATH"
git pull origin main
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan capell:upgrade --force --no-interaction
php artisan capell:runtime-refresh --no-interaction
```

Set `SITE_PATH` to the site directory, or use your host's own path variable.

## Check the deploy

```bash
php artisan capell:doctor
```

## Next

- [Deploys and cache refresh](deploy-and-cache-refresh.md)
- [Upgrading Capell](upgrading.md)
