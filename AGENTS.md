# Capell Foundation Monorepo

This repository is the version-aligned `capell-app/capell` foundation aggregate.
It owns the stable contracts shared by Core, Admin, Frontend, Installer, and
Marketplace. Optional product features live in the companion packages repository;
customer account, marketing, billing, and operational workflows live in
`capell-app`.

## Ownership Boundaries

- `packages/core` owns neutral CMS models, sites, pages, layouts, themes, media
  contracts, settings support, package discovery, install/upgrade primitives,
  publication invariants, and deterministic SiteSpec validation/import. It must
  not import sibling UI packages, optional package classes, or AI provider
  runtimes.
- `packages/admin` owns the Filament authoring workspace, policies, resources,
  settings shell, dashboard slots, and admin extension bridges. Domain work
  belongs in Actions and structured boundaries in Data objects; admin state must
  never enter public output.
- `packages/frontend` owns anonymous request resolution, rendering, render hooks,
  HTML caching, static-export behaviour, and public assets. Public Blade receives
  hydrated render data and must not query, lazy-load, resolve models, or expose
  authoring state.
- `packages/installer` owns the browser installer and concrete install-guide
  patches. Core owns the patch editors, `Patch` contract, and
  `InstallPatchRegistry`; Core invokes registered patches without importing an
  Installer class.
- `packages/marketplace` owns Marketplace transport, account linking, acquisition,
  entitlement/install coordination, and package-operation UI. Core remains the
  owner of package discovery and lifecycle state.
- Independently installable, disableable, versioned, or sold features belong in
  `../capell-packages-4`. Packages integrate through public contracts; they do not
  reach into one another's internals or make Core depend on their models.
- Core owns publication state, readiness, and transition contracts. Publishing
  Studio owns approvals, reviewers, release workspaces, comments, notifications,
  orchestration, rollback history, and other advanced collaboration records.
- Core owns deterministic SiteSpec import and safe media intake. Prompt execution,
  provider clients, metering, and AI authoring products belong in optional
  packages.

## Public Rendering Safety

Anonymous HTML, cached responses, crawler output, and static exports must contain no
authoring HTML or JavaScript, edit markers, model IDs, field paths, permissions,
package names, component internals, or signed editor URLs. Frontend authoring is a
post-load, authenticated-admin concern. Public-output, render-hook, cache, theme,
fragment, or export changes require absence assertions as well as expected-content
tests.

## Extension Contracts

- Register package behaviour from the correct manifest-v3 provider bucket.
  `metadata` and `install` providers are lifecycle-safe; `runtime`, `admin`, and
  `frontend` providers load only in their intended enabled contexts. Providers wire
  contracts and delegate work; they do not perform domain writes.
- Register page types and model aliases through `CapellCore::registerPageType()` and
  `CapellCore::registerModels()`. Use model interceptors only through the documented
  facade contract.
- Use `AdminBridgeRegistrar` for grouped Admin integration and
  `CapellAdmin::contributeToAdminSurface()` for focused surfaces. Extend forms,
  tables, actions, and workflows through their contract `TAG` constants, including
  `PageSchemaExtender::TAG` and `PageTableExtender::TAG`; never publish or copy Core
  schemas (`capell:admin-publish-schemas` is not an extension path).
- Register public hooks and assets through `RenderHookRegistry`,
  `FrontendResourceRegistry`, `TailwindAssetsRegistry`, and related typed
  registries. Declare cache dependencies through `CacheInvalidationRegistry`.
- Register package settings through `PackageSurfaceRegistrar`; external Admin
  integrations use `AdminBridgeRegistrar`. Do not write directly to the settings
  registry outside its supported provider boundary.
- Add install-time host edits through `InstallPatchRegistry`, not duplicated file
  editors or package imports in Core.
- Add every Core migration filename to
  `packages/core/src/Concerns/HasMigrations.php`. Settings migrations live in
  `database/settings/`, are registered by the owning install/setup flow, and must
  tolerate the settings table not existing during early bootstrap.
- `docs/packages/extension-surface-catalog.{json,md}` is generated from executable
  catalogue metadata. Stable surfaces, public signatures, Core migrations,
  constraints, and config keys are protected by
  `docs/packages/stable-extension-api-baseline.json`; compatibility drift requires
  an explicit matching decision record.

## Screenshot Evidence

- Documentation, release, and promotional screenshots are accepted only when
  produced by the shared `capell-screenshot-runner` through this repository's
  `@capell-app/screenshot-tools` entry point and backed by its report/receipt
  provenance. Manifests must retain `generatedFor` set to
  `shared-capell-screenshot-runner` and `provenancePolicy` set to `runner-only-v1`.
- Direct captures from `capell-app`, `capell.test`, or an ad-hoc browser session are
  diagnostics only and must remain ignored. They cannot be promoted as accepted
  artifacts, and capturing Core evidence does not authorize changes in the
  consuming App.
- Validate manifests with `npm run screenshots:check`. Prepare and capture through
  `bash scripts/local-core-screenshots.sh --package <name>` (omit the filter for all
  Core manifests). Validate documentation coverage and provenance with
  `npm run docs:screenshots:check` and `npm run docs:screenshot-receipts`.

## Verification Commands

- Focused Pest:
  `vendor/bin/pest packages/<package>/tests/path/ToTest.php --configuration=phpunit.xml`
- Changed-file formatting: `composer lint:changed`
- Source analysis while iterating: `composer analyze:source`
- Standard repository gate: `composer preflight`
- Full repository gate before completion: `composer preflight:all`
- Local equivalent of the hosted Test All topology:
  `composer test:all:matrix:local`
- Extension contracts: `composer check:extension-surfaces` and
  `composer check:stable-extension-api`
- Documentation contracts: `composer check:docs-links`,
  `composer check:docs-orphans`, `composer check:docs-requirements`, and
  `composer check:docs-screenshots`

Run the narrowest relevant command first. Rendering/cache changes need focused
Frontend safety tests; migration, config, constraint, or public-extension changes
also need both extension-contract checks.

## Local Hazards

- The supported runtime is PHP 8.4. Do not interpret failures from a different host
  PHP as release evidence.
- In a worktree, run `bash scripts/init-worktree.sh`. Never symlink `vendor/` or
  `vendor/composer/`: Composer then resolves Capell classes from the primary
  checkout and green tests exercise the wrong source. Verify a changed class with
  `ReflectionClass::getFileName()`; use a real `composer install` for authoritative
  full-suite proof when the hybrid vendor limitation is relevant.
- Do not run dependency-mutating Composer commands in a hybrid worktree. Its
  third-party package directories are intentionally shared with the primary
  checkout.
- `composer preflight` requires the pinned `node_modules`; run `npm ci` when it is
  absent. `preflight:all` intentionally applies Rector and Pint, so review its diff.
- `composer test:all:matrix:local` verifies the committed exact `HEAD` in isolated
  worktrees/containers. It does not include uncommitted changes.
- Host and container PHPStan caches must remain separate. `phpstan.neon` uses
  `var/phpstan/full`, while Docker masks `var/`; a cache containing paths from the
  other execution context can raise `phpstorm-stubs` “is not a file” internal
  errors. Confirm the cached path provenance before clearing only that cache.
- Keep local Composer path repositories out of public `composer.json` and
  `composer.lock`; `composer check:composer-paths` enforces this.

Use available Boost capabilities and reusable skills as live tooling. Do not copy
generated Boost guideline dumps or static skill inventories into this file.
