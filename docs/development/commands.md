# Capell CLI Command Index

![Capell CLI Command Index screenshot](../images/admin-dashboard.png)

This page is the short command map for the host packages in this repository. Run `php artisan list capell` inside an installed app for the live command list, including optional add-on packages.

## Core

| Command                         | Use it for                                                                 |
| ------------------------------- | -------------------------------------------------------------------------- |
| `capell:install`                | Run the main installer, optional demo setup, package setup, and first user |
| `capell:upgrade`                | Run Capell upgrade phases, migrations, registered steps, and cache cleanup |
| `capell:rollback`               | Roll back a recorded upgrade step                                          |
| `capell:doctor`                 | Run installation and environment checks                                    |
| `capell:publish-migrations`     | Publish Capell migrations or selected migration files                      |
| `capell:delete-migrations`      | Delete published migration files for an extension                          |
| `capell:publish-components`     | Publish frontend Blade component views                                     |
| `capell:cache-components`       | Cache registered component metadata                                        |
| `capell:clear-components-cache` | Clear cached component metadata                                            |
| `capell:package-cache`          | Refresh cached installed package metadata                                  |
| `capell:package-cache:clear`    | Clear cached installed package metadata                                    |

## Extension Lifecycle

| Command                                   | Use it for                                                                      |
| ----------------------------------------- | ------------------------------------------------------------------------------- |
| `capell:extension-install`                | Run install workflows for one or more installed extension packages              |
| `capell:extension-uninstall`              | Uninstall extensions, optionally deleting extension data or Composer packages   |
| `capell:extensions:repair-composer-drift` | Repair Composer-actionable drift between `capell_extensions` and Composer state |
| `capell:extension-audit`                  | Validate a package directory or `capell.json` against extension contracts       |
| `capell:extension-playground`             | Inspect an extension from a package name, directory, or manifest path           |
| `capell:make-extension`                   | Scaffold a local package with a minimal or full manifest profile                |

## Developer Makers

| Command                 | Use it for                                             |
| ----------------------- | ------------------------------------------------------ |
| `capell:make`           | Preview or run any maker registered in `MakerRegistry` |
| `capell:make-action`    | Generate an Action class under `App\Actions`           |
| `capell:make-data`      | Generate a Laravel Data class under `App\Data`         |
| `capell:make-extender`  | Generate a `PageSchemaExtender`                        |
| `capell:make-schema`    | Generate a schema class                                |
| `capell:make-blueprint` | Generate a page blueprint class                        |

The maker hub accepts `--dry-run`, `--force`, `--name=`, `--type=`, `--source=`, `--livewire`, and `--database` where a maker supports them. Use `capell:make --dry-run` before writing files when you are checking a generator.

## Demo And Test Data

| Command             | Use it for                                                            |
| ------------------- | --------------------------------------------------------------------- |
| `capell:faker`      | Create local test content, optionally including package-provided data |
| `capell:core-faker` | Create core-only local test content                                   |

These are development helpers. Do not run them against production data.

## Admin

| Command                                  | Use it for                                                            |
| ---------------------------------------- | --------------------------------------------------------------------- |
| `capell:admin-install`                   | Install admin package requirements and optionally integrate a panel   |
| `capell:admin-setup`                     | Create initial admin data and wire Capell into a Filament panel       |
| `capell:admin-upgrade`                   | Run admin package upgrade work                                        |
| `capell:admin-upgrade-summary-email`     | Send the configured upgrade summary notification                      |
| `capell:admin-clear-cache`               | Clear Capell admin cache and package cache                            |
| `capell:admin-cache-widgets`             | Cache discoverable admin widgets                                      |
| `capell:admin-clear-widgets-cache`       | Clear discoverable admin widget cache                                 |
| `capell:admin-cache-configurators`       | Cache registered configurators                                        |
| `capell:admin-clear-configurators-cache` | Clear registered configurator cache                                   |
| `capell:admin-publish-resources`         | Publish selected admin resources for advanced app-level customisation |

Do not use older schema publishing commands unless the installed package version still exposes them. Current admin customisation should prefer configurators, extenders, bridges, and resource contributions.

## Frontend

| Command                         | Use it for                                  |
| ------------------------------- | ------------------------------------------- |
| `capell:frontend-install`       | Run frontend install work                   |
| `capell:frontend-after-install` | Build frontend assets after package install |
| `capell:frontend-upgrade`       | Run frontend package upgrade work           |

Static-site generation, sitemap generation, Tailwind aggregation, and demo commands are supplied by optional packages in a consuming app. Treat `php artisan list capell` as the source of truth for that install.

Common optional owners:

| Command                           | Package                     |
| --------------------------------- | --------------------------- |
| `capell:static-site`              | `capell-app/html-cache`     |
| `capell:xml-sitemap`              | `capell-app/site-discovery` |
| `capell:frontend-tailwind-assets` | `capell-app/frontend`       |
| `capell:admin-demo`               | `capell-app/demo-kit`       |
| `capell:demo-kit-full-demo`       | `capell-app/demo-kit`       |

## Repository Scripts

These commands are defined in the root `composer.json` or `package.json`:

| Script                             | Use it for                                                                  |
| ---------------------------------- | --------------------------------------------------------------------------- |
| `composer prepare`                 | Discover Testbench packages                                                 |
| `composer test:fast`               | Run the sharded fast Pest command while developing                          |
| `composer test`                    | Run the full Pest test suite                                                |
| `composer lint`                    | Run changed-file Pint formatting                                            |
| `composer analyze`                 | Run the fast PHPStan configuration                                          |
| `composer preflight`               | Run fast PHPStan and changed-file formatting                                |
| `composer preflight:all`           | Run Composer path checks, Rector, Pint, Prettier, ESLint, PHPStan, and Pest |
| `composer check:root-docs`         | Ensure no unexpected Markdown files are added to the repository root        |
| `composer check:docs-links`        | Ensure all relative documentation links resolve                             |
| `composer check:docs-orphans`      | Ensure every docs page is reachable from the docs entry points              |
| `composer check:docs-requirements` | Ensure requirement tables agree with `composer.json` constraints            |
| `composer check:docs-env`          | Ensure documented env vars are read by code or explicitly allowlisted       |
| `composer serve`                   | Build and serve the Testbench workbench                                     |
| `npm run screenshots`              | Capture docs screenshots through the configured screenshot runner           |
| `npm run screenshots:check`        | Validate docs screenshot manifests through the configured screenshot runner |
| `npm run docs:publish`             | Sync the local core docs into the external docs app and build it            |

## Naming Rules

- Host commands use `capell:<name>` or `capell:<package>-<verb>`.
- Optional add-ons may add their own `capell:<package>-<verb>` commands.
- Do not copy command names from old planning notes; check the source or run `php artisan list capell` so command documentation reflects shipped behaviour.

See [Artisan commands](artisan-commands.md) for the longer command reference with common options.
