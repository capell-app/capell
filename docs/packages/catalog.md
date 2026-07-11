# Capell Packages and Extensions

![Capell Packages and Extensions screenshot](../images/generated/admin/theme-library-admin-flow.png)

Capell core stays small. This repository owns the host packages that make a Capell application run, while optional product capability lives in installable extension packages with their own manifests, docs, tests, and release process.

Use the generated [Capell Extensions explorer](https://docs.capell.app/packages/) for the current add-on package catalogue. Use this page for the stable host package boundaries and links into package-authoring documentation.

## Host Packages

| Package     | Composer name            | Owns                                                                                           |
| ----------- | ------------------------ | ---------------------------------------------------------------------------------------------- |
| Core        | `capell-app/core`        | Sites, pages, languages, blueprints, settings, package registry, install and upgrade actions.  |
| Admin       | `capell-app/admin`       | Filament panel, resources, dashboards, settings UI, users, page editing, and recovery shell.   |
| Frontend    | `capell-app/frontend`    | Public rendering, frontend middleware, Tailwind aggregation, Blade helpers, and safety guards. |
| Installer   | `capell-app/installer`   | Browser installer, install guide patches, setup progress, reports, and installer cleanup.      |
| Marketplace | `capell-app/marketplace` | Extension browsing, acquisition records, package install operations, and marketplace support.  |

These packages may expose extension points, but they should not absorb optional product features unless the host repo owns a shared contract needed by multiple packages.

## Extension Catalogue

The add-on package universe is intentionally outside this repository. Browse generated extension pages at [docs.capell.app/packages](https://docs.capell.app/packages/) and use package-owned READMEs for exact install commands, models, Actions, migrations, and public rendering details.

For local development, install external packages through the consuming application's Composer repositories. Do not add a package to `capell-4` only because it exists beside this checkout.

First-party package information pages in this host repo:

| Package                     | Product group     | Composer name           | Notes                                                                                          |
| --------------------------- | ----------------- | ----------------------- | ---------------------------------------------------------------------------------------------- |
| [AI Creator](ai-creator.md) | Capell Commercial | `capell-app/ai-creator` | Reviewed AI-assisted creation sessions for existing Capell sites with package recommendations. |

## Capell Foundation

Foundation add-ons such as content sections, navigation, frontend authoring, media, HTML cache, and discovery are documented in the generated extension catalogue. Core documentation may mention them as examples, but their inventory and product grouping stay package-owned.

## Capell Operations

Operational add-ons such as migration assistance, diagnostics, monitoring, and exception reporting are documented in package-owned docs and the generated extension catalogue. Core and Admin provide shared contracts or shells only when the host owns that boundary.

## Capell Search & SEO

Search, SEO, discovery, and related growth packages are optional extensions. Use their package docs for feature behavior and use the host docs only for shared extension points and public-rendering safety rules.

## For Package Authors

| Page                                                                  | Covers                                                                                                        |
| --------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| [Packages](README.md)                                                 | Package shape, service providers, Actions/Data/settings, extension points, migrations, and release checklist. |
| [Package authoring](../platform/package-authoring.md)                 | How to build package-owned admin, frontend, lifecycle, and marketplace-ready surfaces.                        |
| [Installer extension contracts](installer-extension-contracts.md)     | Installer-facing manifest keys, lifecycle Actions, settings migrations, and package setup tests.              |
| [Marketplace extension contracts](marketplace-extension-contracts.md) | Marketplace-facing manifest keys, package operation boundaries, and catalogue/lifecycle tests.                |
| [Extension examples](extension-examples.md)                           | Copy-paste examples for host extension points and public-output safety.                                       |
| [Extension point chooser](extension-point-chooser.md)                 | Which host extension point to use for a package contribution.                                                 |
| [Extension troubleshooting](extension-troubleshooting.md)             | How to debug missing package contributions, stale caches, route fallback, settings, and marketplace issues.   |
| [Host, package, or app code](../development/package-boundaries.md)    | Where a feature or document should live.                                                                      |
