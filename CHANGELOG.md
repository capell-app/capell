# Changelog

All notable changes to Capell are documented in this file. Release notes for
the 1.x line are published as [GitHub Releases](https://github.com/capell-app/capell/releases)
on this repository; each published release is appended here automatically by
the `Update Changelog` workflow.

## Unreleased

- Permanently deleting a site is refused while a package holds records that must be kept, such as form submissions under legal hold, which the database would otherwise delete with the site. Packages report such records by tagging a `Capell\Core\Contracts\Privacy\RetainsSiteRecords` implementation with `RetainsSiteRecords::TAG`. The site policy also denies `forceDelete` in that case.

### Added

- Packages can catalogue every outbound message, whatever the channel, through a neutral contract: a stable source key per message class and channel (with raw mail kept as its own unattributed source), the owning package resolved from installed manifests, and discovery of the Mailable and Notification classes in installed packages before they are sent.

### Fixed

- A page whose type is set to never be cached no longer has its render data stored and reused between requests. Its content widgets are now built for every request, so output that depends on the visitor cannot reach another visitor.
- A fallback-language path no longer moves permanently to the translated URL when the requested language's domain is disabled; it answers not found, as it already did when the language had no domain. The one-hour lifetime of that redirect is now documented in the page cache architecture notes.

### Added

- Reorganised the admin sidebar into fixed groups (Dashboard, then Website, Media, Design, Marketing, Business, Reports, Users, Settings and Developer). Packages choose a group from `NavigationGroupEnum` instead of registering their own, can replace a core item with `CapellAdmin::replaceNavigationItem()`, and list their settings pages on the Settings page instead of the sidebar. Limits of 8 groups and 10 items per group are enforced by tests, and Recently deleted moves to the sidebar footer. Old group keys and labels are no longer mapped: an item that returns one appears under Settings.
- Documented website-specific page fields with a Client name walkthrough, extension examples, storage and lifecycle comparisons, and reproducible screenshot fixtures.

- Added a progress bar across the `capell:install` steps and a closing summary of 60 lines or fewer (what was installed, the site and admin addresses and the next steps). Plain step lines replace the bar in CI and `--no-interaction` runs, and `-v` keeps the detailed install log and full handoff.
- Added a short install-shaped "What next" list to the `capell:install` handoff (sign-in, first page, theme, content packages or the pending asset build), exported as `tips` in the JSON handoff.
- Added a simple CLI installation flow with one confirmation for foundation defaults, editable customisation, reusable profiles, optional application URL updates and frontend dependency builds.
- Added fail-closed Project Build target compatibility verification and a consumer-owned package installation boundary for applying signed manifests in exact release order.
- Added the Core Filament-neutrality seam and the draft 2.x compatibility decision, dependency baseline, consumer fixture, and old-package failure wording. The 1.x adapters and `filament/support` requirement remain active.
- Added an ordinary draft-save control in the configured page action position without duplicating extension-provided draft actions.

### Changed

- Moved the public frontend resource contract into Core. A package that adds styles or scripts to public pages now depends only on Core, and needs `capell-app/core` `^1.0.79`. Update your imports; the old Frontend names are removed, with no aliases:
  - `Capell\Frontend\Contracts\FrontendResourceContributor` and `FrontendResourceSourceData` → `Capell\Core\Contracts\Rendering\…`
  - `Capell\Frontend\Data\FrontendResourceContextData` and `FrontendRuntimeManifestData` → `Capell\Core\Data\Rendering\…`
  - `Capell\Frontend\Data\Assets\FrontendResourceData`, `FrontendResourceContributionData`, `FrontendResourceActivationData` and `InlineResourceSourceData` → `Capell\Core\Data\Rendering\Assets\…`
  - `Capell\Frontend\Enums\FrontendResourceKind`, `FrontendResourcePlacement`, `RenderingStrategyEnum` and `ScriptExecutionMode` → `Capell\Core\Enums\Rendering\…`

  The contributor tag `capell.frontend.resource-contributor` and every shape stay the same.

- Renamed Core's `backup` and `redirects` config files to `capell-backup` and `capell-redirects`, so publishing Core no longer writes a `config/backup.php` that clashes with spatie/laravel-backup or a redirects package. An app that still sets the old `backup.*` or `redirects.*` Capell keys keeps its values for this minor version and receives a deprecation naming the new file; move them to `config/capell-backup.php` and `config/capell-redirects.php`.
- Added named constructors to the Core exceptions that were empty classes. The component, theme and layout not-found messages now say where to register what is missing; every earlier message text is kept as the start of the new one.
- Grouped Admin navigation with local menus for secondary Design tools; removed the separate marketing dashboard page and composed its widgets into Dashboard.

### Fixed

- Stopped `capell:install` aborting with "no such table: cache" on a fresh app that caches in the database: Data structure cache reads now count as misses until the migrations have created the cache table.
- Registered the compiled Admin theme during panel installation, preserving explicit themes and reporting customised provider methods that need manual configuration.
- Resolved missing installer packages before application or database changes, clarified catalogue licensing and first-site URLs, and reported partial failures with focused recovery commands.
- Preserved local Core path constraints during Composer downloads and avoided borrowing vendor migration stubs from a different installed version.
- Preserved site domain ports and the selected site across page creation, validated duplicate page URLs before saving, and simplified page tools and layout navigation.
- Avoided repeated admin contribution sorting and quadratic receipt deduplication during application boot, preserving registration order and cache invalidation.
- Emitted declared package-specific theme identity tokens while rejecting malformed names, reserved core-property collisions, and values outside their declared vocabulary.
- Published generated theme-token CSS atomically and restored genuine database selection in the full CI matrix.

## v1.0.0 - 2026-07-11

Public re-baseline. The repository history was consolidated into a fresh 1.0.0
baseline on 2026-07-11. Releases continue from this baseline as root `v1.0.x`
tags, with per-package tags for the split packages (core, admin, frontend,
installer, marketplace).

## Pre-reset releases (2025-06-09 to 2026-07-09)

The pre-reset `v2.0.x` and original `v1.0.x` numbers describe releases before
the 2026-07-11 re-baseline. Local clones may still retain these historical tags;
their presence does not establish a supported 2.x line. Their work is included
in the consolidated 1.0.0 baseline above. Use the 1.x release notes and the
split-package tags for the current Foundation line; a historical tag or commit
date does not establish a current package publication or application deployment.
