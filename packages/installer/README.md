# Capell Installer

![Capell Installer package selection and guided setup handoff](docs/assets/readme/hero.jpg)

[![Latest Release](https://img.shields.io/github/v/release/capell-app/installer?style=flat-square&label=release)](https://github.com/capell-app/installer/releases/latest)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/capell-app/installer.svg?style=flat-square)](https://packagist.org/packages/capell-app/installer)
[![Tests](https://github.com/capell-app/capell/actions/workflows/test-full.yml/badge.svg?branch=main)](https://github.com/capell-app/capell/actions/workflows/test-full.yml)
[![PHP Quality](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml/badge.svg?branch=main)](https://github.com/capell-app/capell/actions/workflows/code-quality-and-styling.yml)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](#requirements-and-support-policy)
[![Laravel](https://img.shields.io/badge/Laravel-12.41%2B%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)](#requirements-and-support-policy)
[![Documentation](https://img.shields.io/badge/docs-docs.capell.app-blue?style=flat-square)](https://docs.capell.app)

Capell Installer gives a fresh Laravel application a guided, browser-based Capell setup. You require one package, open `/install`, and the flow checks your environment, installs the Capell packages you pick, and creates the first admin user. Reach for it when you are bootstrapping a new Capell app; skip it if you prefer to wire packages together by hand. It is a setup tool, not a runtime dependency, and it can remove itself once installation finishes.

## Package boundary

Installer owns:

- the `/install` browser setup routes, plus the progress and report routes
- Filament install pages and the not-installed dashboard Filament widget
- install guide patch discovery and safe patch application
- first-admin defaults, default package selection, environment preflight checks, and setup package removal

Installer does not own:

- install primitives, package manifests, or migration orchestration (that is `capell-app/core`)
- admin resources beyond installer-specific pages and widgets (that is `capell-app/admin`)
- runtime package management, or marketplace install authorisation (that is `capell-app/marketplace`)
- public or admin runtime behaviour after the app is installed

The browser installer works without the Admin panel. The Filament installer pages and dashboard widget only register when `capell-app/admin` and Filament are present.

## Install

This is the recommended entry point for installing Capell. Requiring it pulls in `capell-app/core`; the guided flow then composer-requires the admin and frontend packages you choose, and can remove the installer afterwards (`--remove-installer`). If you prefer to pick packages by hand, skip this and require `capell-app/core` (plus `admin` or `frontend` as needed) directly. The complete install guide is published at [docs.capell.app](https://docs.capell.app).

```bash
composer require capell-app/installer
```

Open `/install` in the host app to run the browser flow. The route is guarded by `EnsureNotInstalled` unless reinstall is explicitly allowed. The installer package does not require `capell-app/admin`. If you select the admin package during setup, Capell verifies that Composer can resolve it, requires it, scaffolds the Filament panel, and runs the admin integration.

The package config supports these setup env values:

| Env var                         | Purpose                                                                                         |
| ------------------------------- | ----------------------------------------------------------------------------------------------- |
| `CAPELL_SETUP_ALLOW_REINSTALL`  | Allow the browser installer to run on an already installed app. Defaults to `APP_DEBUG`.        |
| `CAPELL_SETUP_COMPOSER_BINARY`  | Composer binary used by installer checks and commands.                                          |
| `CAPELL_SETUP_PHP_BINARY`       | PHP CLI binary used by installer checks and commands.                                           |
| `CAPELL_SETUP_DEFAULT_PACKAGES` | Comma-separated package list selected by default.                                               |
| `CAPELL_SETUP_ADMIN_NAME`       | Prefill the first admin name.                                                                   |
| `CAPELL_SETUP_ADMIN_EMAIL`      | Prefill the first admin email.                                                                  |
| `CAPELL_SETUP_ADMIN_PASSWORD`   | Prefill the first admin password as plaintext input; Capell hashes it when the user is created. |

Treat `CAPELL_SETUP_ADMIN_PASSWORD`, installer progress URLs, and installer reports as bootstrap secrets. Do not commit real setup credentials, and remove or restrict installer access once the app is installed.

Example local demo defaults:

```dotenv
CAPELL_SETUP_ADMIN_NAME="Demo Admin"
CAPELL_SETUP_ADMIN_EMAIL=admin@example.test
CAPELL_SETUP_ADMIN_PASSWORD=password123
```

Do not pass an encrypted or already-hashed value to `CAPELL_SETUP_ADMIN_PASSWORD`. Use the password you want to type on the login form; Laravel stores the hashed password in the `users.password` column during setup.

## Quick example

The whole setup fits in one command and one page visit:

```bash
composer require capell-app/installer
```

Then open `/install` in your browser. The guided flow runs preflight
checks, installs the Capell packages you select (admin, frontend, ...),
creates the first admin user, and can remove itself when setup finishes.

## Runtime surfaces

- Provider: `Capell\Installer\Providers\InstallerServiceProvider`
- Config: `config/capell-installer.php`
- Routes: `routes/web.php`
- Controller: `Capell\Installer\Http\Controllers\InstallController`
- Middleware: `Capell\Installer\Http\Middleware\EnsureNotInstalled`
- Pages: `InstallCapellPage`, `InstallGuidePage`, `InstallProgressPage`
- Actions: `GetActiveInstallAction`, `RemoveSetupPackageAction`, `ApplyInstallGuidePatchesAction`
- Patch registry: `Capell\Installer\Support\InstallGuide\PatchRegistry`

The delete-installer route delegates to `RemoveSetupPackageAction`, which removes the setup package from the host app after installation. CLI installs can pass `--remove-installer` for prompt-free removal. Removal only happens after a fully successful install. If a Composer requirement, Filament scaffolding step, package setup step, admin integration, or health check fails, `capell-app/installer` stays installed so you can retry and debug.

## Install guide patches

Install guide patches are explicit, reviewable changes for common host-app setup tasks. They are the Installer package's main extension point. Keep them small, idempotent, and safe to re-run.

Patch classes implement the Core-owned `Capell\Core\Support\Patching\Patch` contract, live under `Capell\Installer\Support\InstallGuide\Patches`, and are registered through `PatchRegistry`. Patches that should also run during `capell:install` are contributed to Core's `Capell\Core\Support\Install\InstallPatchRegistry` from the installer service provider. Cover patch behaviour with focused tests so a failed patch explains what went wrong instead of leaving a half-edited host file.

The browser installer discovers package and theme choices from Capell package metadata. To appear during setup, a package must be installable by the web PHP process, present in Composer repositories, and described by `capell.json` metadata that the package registry can read. The installer checks each selection with Composer before it starts mutating the application.

## Verification

Installer tests run from a checkout of the Capell monorepo, which supplies the Pest bootstrap and development dependencies this package needs. From the monorepo root, run installer tests after changing installer routes, setup validation, preflight checks, patching, or package removal:

```bash
vendor/bin/pest tests
```

## Requirements and support policy

| Surface | Supported versions                          |
| ------- | ------------------------------------------- |
| PHP     | `^8.4`                                      |
| Laravel | Host Laravel `^12.41.1` or `^13.0` via Core |
| Core    | The same release as this package            |

Each Capell 1.x minor receives security fixes for 24 months from its release date, and the latest 1.x minor is always supported. Upgrade all installed Capell foundation packages together to the same supported release before requesting a fix. See the [Capell security policy](https://github.com/capell-app/capell/security/policy) for vulnerability reporting.

Support covers the dependency ranges above. When an upstream release reaches its own end of life earlier, upgrading that dependency may be required to receive a safe fix.

## Troubleshooting

| Symptom                                             | Check                                                                                                                | Fix                                                                                                    |
| --------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `/install` is unavailable on a fresh app            | `php artisan route:list --name=capell-installer`                                                                     | Confirm the provider was discovered and run `php artisan optimize:clear`.                              |
| `/install` says Capell is already installed         | `InstallerInstallationState::capellIsInstalled()`                                                                    | Use the admin panel, or set `CAPELL_SETUP_ALLOW_REINSTALL=true` only for a controlled local reinstall. |
| Preflight reports the wrong PHP or Composer binary  | `php artisan config:show capell-installer.php_binary` and `php artisan config:show capell-installer.composer_binary` | Set `CAPELL_SETUP_PHP_BINARY` or `CAPELL_SETUP_COMPOSER_BINARY`, then clear config cache.              |
| Optional packages do not appear                     | `composer show vendor/package --available` from the same app                                                         | Fix Composer repositories/auth so the web process can resolve the package.                             |
| Default admin fields are blank                      | `php artisan config:show capell-installer.admin_user`                                                                | Set the `CAPELL_SETUP_ADMIN_*` env values or override the config.                                      |
| Progress says the session expired                   | Check the cache driver and `capell.install.{installId}.*` keys                                                       | Restart the installer with a persistent cache store or use the CLI installer.                          |
| A guide patch fails                                 | Read the patch `reason()` and matching patch test                                                                    | Fix the host file or update the patch probe/apply logic with regression coverage.                      |
| Reports/progress pages remain reachable after setup | `composer show capell-app/installer`                                                                                 | Remove the package or restrict access immediately.                                                     |

## Development

Package development and coordinated verification happen in the [capell-app/capell monorepo](https://github.com/capell-app/capell). Split package repositories are release mirrors; use [docs.capell.app](https://docs.capell.app) for cross-package guidance. See the [contribution guide](https://github.com/capell-app/capell/blob/main/CONTRIBUTING.md), [security policy](https://github.com/capell-app/capell/security/policy), and [licence](https://github.com/capell-app/capell/blob/main/LICENSE.md).

## Further reading

| Page                                   | Covers                                           |
| -------------------------------------- | ------------------------------------------------ |
| [Installer overview](docs/overview.md) | Installer responsibilities and setup boundaries. |

The complete installation and package-selection guides are published at [docs.capell.app](https://docs.capell.app).
