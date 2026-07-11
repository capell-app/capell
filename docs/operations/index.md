# Operations

![Capell Operations screenshot](../images/generated/admin/site-health-page.png)

Use this page during deploys, upgrades, incidents, and production checks.

> **Who's this for?** Operators and developers running an installed Capell site. Deploys, caches, upgrades, and incidents.

## First Checks

| Symptom                         | Check                                                                                               |
| ------------------------------- | --------------------------------------------------------------------------------------------------- |
| Public pages are stale          | Cache state, queue worker, optional HTML Cache package, CDN purge.                                  |
| Admin is missing pages/settings | `php artisan optimize:clear`, permissions, package install state.                                   |
| Install fails in CI             | Missing non-interactive flags or private Composer credentials.                                      |
| Marketplace cannot install      | Account connection, Marketplace URL, webhook URL, package compatibility.                            |
| Public output looks unsafe      | Search response HTML for authoring markers, model IDs, signed admin URLs, selectors, package names. |

## Site Health

Site Health is the operator-facing read-only page for launch and post-deploy checks. It shows whether public traffic can be served safely before anyone edits content.

**Use it to check:**

- cache and static-output state
- public HTML safety
- server/runtime readiness
- optimizer/static generation readiness when optional packages are installed
- package and extension health signals

If Site Health reports a missing optional package table, check whether the package is installed and whether its migrations have run before treating it as a host bug.

Use the [Site Health runbook](site-health.md) for the full check list.

## Lockdown

Lockdown blocks public frontend traffic and most admin access during a suspected compromise or high-risk maintenance window. Break-glass admins can still access the admin surface.

**Use Lockdown when:**

- public content may be unsafe
- package state is inconsistent after a failed deploy
- you need to stop cached public pages while investigating

After enabling or disabling Lockdown, clear runtime caches and verify public routes from a signed-out browser. The [Lockdown runbook](lockdown.md) covers enable/disable commands, break-glass access, and verification steps.

## Upgrades

Standard host upgrade flow:

```bash
composer update capell-app/capell -W
php artisan capell:upgrade
php artisan optimize:clear
php artisan queue:restart
```

Before upgrading production, check:

- database, `.env`, and `storage/` backups exist
- queue workers can run
- optional package migrations are included
- `php artisan list capell` shows expected package commands
- Site Health is clean or known issues are documented

Rollback behavior depends on the app deploy platform and database backup strategy. Capell can record upgrade state, but it cannot replace infrastructure-level rollback.

Use the [backup and restore runbook](backups.md) before production changes. Use the [upgrade runbook](upgrading.md) for durable run tracking, package compatibility, migrations, cache clearing, and post-upgrade checks. For a product-facing explanation of the feature, use [Durable Upgrade Operations](../platform/upgrade-operations.md).

## Marketplace

Marketplace account linking is the normal setup path. Public domain verification is only needed when Marketplace policy requires a stronger production trust signal.

Useful checks:

```bash
php artisan config:show capell-marketplace.marketplace.base_url
php artisan config:show capell-marketplace.marketplace.webhook_url
php artisan config:clear
```

The default Marketplace API URL is:

```env
CAPELL_MARKETPLACE_URL=https://capell.app/api/v1
```

If verification fails, check the exact domain, public challenge URL, redirects/auth middleware/CDN rules, and the latest Marketplace registration row. Local hosts such as `.test`, `.localhost`, and `127.0.0.1` can be account-linked but cannot be publicly verified unless exposed through a real public hostname.

Use the [Marketplace package overview](../../packages/marketplace/docs/overview.md) for account connection, verification, heartbeat, cache, and install-authorization details. Use [Troubleshooting](troubleshooting.md) for copy-paste checks.

For deeper Marketplace incidents, use [Debugging Marketplace](debugging-marketplace.md).

## Common Fixes

[Troubleshooting](troubleshooting.md) holds the full, copy-paste fix for each of these. Match your symptom, then click through for the cause, commands, and what you should see afterwards.

| Symptom                                          | Fix                                                                                                                                     |
| ------------------------------------------------ | --------------------------------------------------------------------------------------------------------------------------------------- |
| `/admin` returns 403 or login loops              | [403 or cannot log in](troubleshooting.md#admin-returns-403-or-will-not-let-you-log-in)                                                 |
| Dashboard loads but Capell resources are missing | [Pages or Settings are missing](troubleshooting.md#the-dashboard-loads-but-pages-or-settings-are-missing)                               |
| Published pages still show old content           | [Published pages still show old content](troubleshooting.md#published-pages-still-show-old-content)                                     |
| Frontend shows Laravel's welcome page            | [Laravel's default welcome page](troubleshooting.md#the-frontend-shows-laravels-default-welcome-page)                                   |
| Non-interactive install fails with `Required.`   | [`NonInteractiveValidationException: Required.`](troubleshooting.md#laravelpromptsexceptionsnoninteractivevalidationexception-required) |
| Composer cannot find Capell packages             | [`composer require` cannot find the package](troubleshooting.md#composer-require-capell-appcapell-cannot-find-the-package)              |
| Vite cannot resolve package CSS imports          | [`Can't resolve 'swiper/...'` during `npm run build`](troubleshooting.md#cant-resolve-swiper-during-npm-run-build)                      |

For the full list of error strings and fixes, see [Troubleshooting](troubleshooting.md).
