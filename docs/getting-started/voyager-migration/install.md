# Install Capell beside Voyager

**Draft — not yet verified end to end.**

## What this step achieves

Prepare the replacement CMS while keeping application-owned tables and behaviour intact.

## Before you start

A pinned compatible Laravel/PHP release, a source backup, storage snapshot, destination backup, tested restore and the required package entitlements. Upgrade the framework separately from changing content rendering.

## Procedure and exact commands

Keep Voyager source tables available to the importer without loading Voyager PHP classes on the new framework. Install the receiving site, layouts and blueprints, then the optional importer packages and their declared dependencies. Keep custom checkout and account code on its existing tables.

The dependency and preview steps below were run from the receiving application's
root on disposable data using released Admin, Frontend and Installer v1.0.76,
Laravel 13 and PHP 8.4. Both the source and receiving application used MariaDB
10.11.19. Completing the installation and its rollback is **not yet verified**.

First add the three packages to the application's Composer requirements at the
compatible release you have selected. The rehearsal used `^1.0.76`. With that
manifest change in place, the following command installed the dependencies
successfully (exit 0):

```sh
composer update capell-app/admin capell-app/frontend capell-app/installer --with-all-dependencies --no-scripts --prefer-dist --no-interaction --no-progress
```

Review the lockfile before committing it. Additional dependencies are expected;
check changes to existing packages separately. If resolution stops at an old VCS
repository that no required package or application caller uses, remove that
unused repository entry. Do not change authentication permissions to access an
unused repository. `--no-scripts` defers application boot hooks; it does not
complete the installer.

Publish Capell Admin's configuration before using the new user model on the web:

```sh
php artisan vendor:publish --tag=capell-admin-config --no-interaction
```

This completed with exit 0. The shipped `filament-shield.php` configuration sets
`panel_user.enabled` to `false`, preventing registration from automatically
granting panel membership. Keep the application's existing customer IDs and
password hashes; give legacy role relationships a distinct name before adding
Spatie's `HasRoles` trait. Review the installer's user-model and runtime-bootstrap
patches against the actual application instead of overwriting custom code.

Preview installation with an existing seeded administrator account. The address
below belongs to the disposable fixture; use your own seeded account:

```sh
php artisan capell:install --packages=capell-app/admin,capell-app/frontend --table-prefix=capell_ --theme=none --user=fakeadmin@example.test --no-seed-default-data --no-interaction --plan
```

The preview exited 0 and printed `Capell Install Plan`, the selected existing
administrator and the lifecycle steps. It explicitly reported that no
installation changes had been applied. Customised user and bootstrap files were
reported as requiring manual changes. Resolve those changes and review the table
plan before applying installation. The command without `--plan` is **not yet
verified**; a successful preview is not proof that migrations ran.

## What you should see

Capell: installed site dashboard with the chosen site visible. Screenshots must not include customer activity.

Screenshots are **not yet captured or accepted**. Do not substitute screenshots
from a live site or an unrelated workflow.

## Check it worked

Prove installation against both a fresh schema and a schema containing the legacy table names. Compare source schema and data before and after. Not yet verified.

The verification command and expected output are **not yet verified**.

## What can go wrong and how to recover

Stop on an unresolved table collision, unsupported host user key type or missing entitlement. Do not rename legacy tables to get past an installer failure.

## Roll back

Restore the destination database and release together. Keep the source and its files unchanged; do not remove source tables as part of installation.

## Next

Return to the [migration chapter list](index.md).
