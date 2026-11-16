# Deploy recipes

Use one order for every routine deploy. The Deployer recipe below was run on a fresh starter-kit application. The Forge and Ploi scripts use the same commands but were not run on those hosts, so check the result with `capell:doctor` on yours. Back up the database first. The server needs Node 20 or later and npm.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan capell:upgrade --force --no-interaction
npm ci --no-audit --no-fund
php artisan capell:frontend-after-install --apply --no-interaction
php artisan capell:runtime-refresh --no-interaction
```

Then reload PHP-FPM, and [refresh public HTML](deploy-and-cache-refresh.md#refresh-public-html-in-the-right-order) if HTML Cache is installed.

`capell:upgrade` runs migrations and upgrade steps. `capell:frontend-after-install --apply` generates the Capell frontend stylesheet and runs `npm run build`. Generated CSS is not kept in Git, so a deploy that skips this step fails Capell Doctor. `capell:runtime-refresh` rebuilds caches, warms pages, runs Doctor and restarts queues. Run `capell:upgrade --dry-run` first when you want to review the plan.

Run the recipe in a fresh release directory, as Deployer, Forge and Ploi do. Installing with `--no-dev` over a directory that was installed with development packages makes `composer install` stop in `package:discover` with `Class "Laravel\Boost\BoostServiceProvider" not found`, because the generated runtime caches still list the development providers. If you must convert such a directory, delete those caches first, then run the recipe:

```bash
rm -rf bootstrap/cache/capell-runtime bootstrap/cache/capell-*.php bootstrap/cache/filament
```

`capell:runtime-refresh` rebuilds the caches again. Reload PHP-FPM after the install so running workers do not keep serving the old provider list.

## Deployer

The recipe was run with Deployer 8 and its `laravel` recipe. Share `storage` and `.env` between releases, then add these tasks, which run before `deploy:symlink`:

```php
task('capell:upgrade', fn () => run('cd {{release_path}} && {{bin/php}} artisan capell:upgrade --force --no-interaction'));
task('capell:frontend', fn () => run('cd {{release_path}} && npm ci --no-audit --no-fund && {{bin/php}} artisan capell:frontend-after-install --apply --no-interaction'));
task('capell:refresh', fn () => run('cd {{release_path}} && {{bin/php}} artisan capell:runtime-refresh --no-interaction'));
task('artisan:migrate', fn () => null);

before('deploy:symlink', 'capell:upgrade');
after('capell:upgrade', 'capell:frontend');
after('capell:frontend', 'capell:refresh');
```

The `laravel` recipe would run `artisan:migrate` before `capell:upgrade`, so the empty `artisan:migrate` task above leaves migrations to `capell:upgrade`. Reload PHP-FPM after `deploy:symlink`.

## Forge and Ploi

Open the site's deploy script. Keep the lines your host generated to enter the site directory and pull the branch, and keep its PHP-FPM reload line at the end. Replace the host's Composer, migrate and cache lines with these, using the host's PHP and Composer variables if your script has them:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan capell:upgrade --force --no-interaction
npm ci --no-audit --no-fund
php artisan capell:frontend-after-install --apply --no-interaction
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
