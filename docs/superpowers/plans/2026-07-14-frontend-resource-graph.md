# Frontend Resource Graph Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Capell's pre-release frontend asset pipeline with one typed, secure, dependency-aware resource graph across Core, companion packages, documentation, and the marketing application.

**Architecture:** Trusted PHP registers immutable resource groups or contextual contributions. A single resolver validates and expands declarations into a stable plan consumed by the default renderer, lazy browser runtime, cached/static rendering, diagnostics, and Frontend Optimizer. LayoutBuilder stores only trusted group selections and activation targets; public output receives resolved browser attributes and opaque tokens without internal ownership metadata.

**Tech Stack:** PHP 8.3+, Laravel 12 Vite integration, Spatie Laravel Data, Pest, JavaScript ES modules, Composer package monorepos, Blade/Livewire, Capell CMS seed snapshots.

---

## Repository boundaries and file structure

- Core repository: `/Users/ben/Sites/packages/capell/capell-4` owns declarations, resolution, rendering, runtime, installation planning, Doctor integration, core documentation, and the breaking upgrade guide.
- Companion repository: `/Users/ben/Sites/packages/capell/capell-packages-4` owns Foundation, LayoutBuilder, widget, Inertia adapter, and Frontend Optimizer migrations.
- Marketing application: `/Users/ben/Sites/capell-app` owns the seeded learning page, cross-links, content inventory, generated frontend build, audits, and route/browser QA.
- Do not commit an intermediate release. Local commits may be repository-scoped, but overlay verification must use all coordinated commits before publishing.

## Task 1: Immutable declarations and trusted source policy

**Files:**

- Create `packages/frontend/src/Enums/FrontendResourceKind.php`, `FrontendResourcePlacement.php`, `ScriptExecutionMode.php`, `CrossOrigin.php`, `ReferrerPolicy.php`, `FrontendResourceHintKind.php`, `FrontendResourceHintAs.php`, `FetchPriority.php`, and `ExternalResourceIntegrityPolicy.php`.
- Create `packages/frontend/src/Contracts/FrontendResourceSourceData.php`.
- Replace `packages/frontend/src/Data/Assets/FrontendResourceData.php` and `FrontendResourceGroupData.php`.
- Create source, contribution, activation, hint, resolved-resource, diagnostic, plan, context, rendered-output, and package-dependency Data objects under `packages/frontend/src/Data/Assets`.
- Create `packages/frontend/src/Exceptions/InvalidFrontendResourceException.php` and `FrontendResourcePlanException.php`.
- Test under `packages/frontend/tests/Unit/Data/Assets`.

- [ ] Write factory tests for style, module script, classic script, inline style, and inline script defaults and invalid kind/source combinations.
- [ ] Run the new Data tests and confirm they fail because the typed API is absent.
- [ ] Implement readonly declarations, named factories, typed enums, stable-handle validation, placement defaults, classic/module execution rules, and async dependency rejection.
- [ ] Implement HTTPS external-source validation rejecting credentials, fragments, protocol-relative URLs, non-HTTPS schemes, malformed SRI, and invalid browser-security attributes while preserving query strings.
- [ ] Implement public, Vite, external, and inline source types; ensure only theme metadata parsing—not trusted constructors—restricts executable sources.
- [ ] Run the focused Data tests and `composer analyze -- packages/frontend/src/Data/Assets packages/frontend/src/Enums`.

## Task 2: Typed registry, selection, and contribution boundary

**Files:**

- Replace `packages/frontend/src/Support/Assets/FrontendResourceRegistry.php`.
- Remove `FrontendResourceGroupBuilder.php` after callers migrate.
- Replace `packages/frontend/src/Contracts/FrontendAssetContributor.php` with `FrontendResourceContributor.php`.
- Replace selected-resource actions with one contribution-building action.
- Update theme metadata Data/resolver tests to permit only local paths and trusted group references.

- [ ] Add tests proving typed group registration, duplicate/conflicting handles, trusted group selection, independent activation targets, and rejection of remote/inline editor metadata.
- [ ] Implement `register(FrontendResourceGroupData $group): void` with globally stable handles and explicit ownership.
- [ ] Implement contextual contribution collection and activation preservation without ranking visible, idle, and interaction strategies.
- [ ] Remove loose arrays and fluent `css()`/`js()` registration after all first-party callers have typed declarations.
- [ ] Run focused registry, metadata, and selection tests.

## Task 3: Resolve one authoritative frontend resource plan

**Files:**

- Create `packages/frontend/src/Actions/ResolveFrontendResourcePlanAction.php` and small validation/graph/Vite helpers where a focused responsibility warrants them.
- Remove `BuildFrontendAssetManifestAction.php`, legacy manifest/requirement Data, conflict detector, and redundant graph builders after migration.
- Update public render, cache, static artifact, performance report, and diagnostics actions to carry `FrontendResourcePlanData`.

- [ ] Add tests for source resolution, Laravel `ASSET_URL`, production Vite imports/CSS/modulepreload expansion, hot mode, transitive dependencies, missing handles, cycles, async violations, aliases, canonical URL deduplication, conflicting attributes, deterministic ordering, independent lazy graphs, eager promotion, production omission, local exceptions, and stable fingerprints.
- [ ] Implement validation and environment-sensitive failure handling with safe structured diagnostics.
- [ ] Implement Vite/public/external/inline resolution and CSP-origin collection without mutating application CSP.
- [ ] Implement dependency expansion, cycle detection, topological layers, compatible aliases, conflicts, lazy activation graphs, and opaque token mapping retained only in server diagnostics.
- [ ] Make the fingerprint cover URLs, integrity, browser attributes, dependencies, placement, hints, activations, and aliases.
- [ ] Update cache/static serialization tests and performance size reporting so external resources are explicitly unmeasurable and never fetched.
- [ ] Run all focused resolver, cache, artifact, diagnostics, and performance tests.

## Task 4: Deep renderer contract and lazy runtime

**Files:**

- Create `packages/frontend/src/Contracts/FrontendResourcePlanRenderer.php`.
- Replace `DefaultFrontendAssetManifestRenderer.php` with `DefaultFrontendResourcePlanRenderer.php`.
- Update `BladeFrontendResponseRenderer.php`, public render Data, Blade layouts, and runtime resources.
- Update JavaScript runtime sources and tests under `packages/frontend/resources` and `packages/frontend/tests`.

- [ ] Add renderer tests for head/body placement, exact external URLs, SRI, crossorigin, referrer policy, module/classic/defer/async, Vite nonce, inline nonce, every hint kind, and escaped inline closure safety.
- [ ] Implement the deep renderer returning head HTML, body-end HTML, and a lazy runtime payload.
- [ ] Add runtime tests for independent visible/idle/interaction triggers, earliest valid shared trigger, sequential dependency layers, concurrent peers, eager state seeding, trusted cross-origin URLs, failure cleanup, retry, fallback events, and opaque output.
- [ ] Implement browser-side revalidation and one canonical state/promise per resource, preserving successful dependencies during retry.
- [ ] Remove Mix rendering and legacy same-origin-only enforcement.
- [ ] Run focused PHP and JavaScript runtime tests and build the Frontend package assets.

## Task 5: Vite input and JavaScript dependency installation planning

**Files:**

- Create `packages/frontend/src/Support/Assets/FrontendPackageDependencyRegistry.php`.
- Create dependency-plan and installation-plan actions/Data.
- Replace `packages/frontend/src/Console/Commands/AfterInstallCommand.php` mutation flow.
- Add `packages/frontend/resources/js/capell-vite-inputs.js`, package export wiring, generated manifest writer, and fresh-install Vite stub integration.
- Update frontend configuration and Doctor checks.

- [ ] Add process-fake tests for npm, pnpm, Yarn, and Bun selection; `packageManager` agreement; conflicting lockfiles; runtime/dev commands; merges; divergent constraints; application pins/resolutions; confirmation; report-only non-interactive execution; explicit apply; unchanged failure output; and exact remediation.
- [ ] Implement deterministic dependency planning with application direct dependencies taking precedence while preserving owner/constraint diagnostics.
- [ ] Generate `bootstrap/cache` input metadata and a JavaScript `capellViteInputs()` helper without regex-editing existing Vite configs.
- [ ] Make install output describe dependencies, Vite entries, published assets, and build command before mutation.
- [ ] Add Doctor failure for missing input integration and report CDN/SRI/CSP/package-manager diagnostics.
- [ ] Remove `VendorAssetEnum::BuildAsset` and `NpmDependency` only after every caller is migrated; preserve Tailwind cases.
- [ ] Run focused installer and Doctor tests.

## Task 6: Companion package migration

**Files:**

- Update Foundation contributor/provider and tests.
- Update LayoutBuilder registry selection/contribution bridge and tests.
- Update every widget package resource registration and tests.
- Update Inertia Vue/React package dependency and Vite entry declarations/tests.
- Replace Frontend Optimizer renderer, asset-set, profile resolution, profile signature, health check, and tests.

- [ ] Register Foundation Vite/public resources and contextual runtime contributions with typed ownership.
- [ ] Make LayoutBuilder the sole bridge from trusted group selection to activation contributions and remove the duplicate contributor path.
- [ ] Migrate all widget resource groups and both Inertia adapters; verify no legacy contract, builder, or vendor subtype caller remains.
- [ ] Make Frontend Optimizer implement `FrontendResourcePlanRenderer`, consume resolved plan semantics directly, retain full fingerprint in cache identity, and never fetch external resources during public requests.
- [ ] Add parity, public-output safety, dependency, activation, optimizer fallback, and package health tests.
- [ ] Run affected package files, then companion `composer preflight` once.
- [ ] Commit the coordinated companion migration without unrelated files.

## Task 7: Canonical developer documentation and upgrade guide

**Files:**

- Create `packages/frontend/docs/frontend-resources.md`.
- Update Frontend overview, root README, render-hook guide, theme-creation guide, package-extension guide, and `docs/operations/upgrading.md`.

- [ ] Document an application npm/Vite dependency, package-published CSS/JS, one-line CDN script, SRI/security attributes, dependent CDN pair, conditional widgets, Vite helper/install plan, CSP responsibilities, diagnostics, testing patterns, render-hook boundaries, and trusted-code policy.
- [ ] Document Mix removal, removed APIs, exact migration mappings, cache invalidation, and coordinated release ordering.
- [ ] Run documentation link/contract tests and search for stale public examples.
- [ ] Commit documentation in its owning repository.

## Task 8: Marketing CMS content and proof links

**Files:**

- Update Capell marketing CMS snapshot/seeder fixtures and tests in `/Users/ben/Sites/capell-app` after inspecting established stable-key and snapshot patterns.
- Update content inventory and internal-link expectations.

- [ ] Add indexed `/platform/delivery/frontend-assets` content covering Laravel ownership, package declarations, conditional loading, deduplication/order, trusted CDN/SRI, diagnostics/impact, and application release responsibility without making hosting/audit guarantees.
- [ ] Add concise proof/link sections to frontend ownership and package extensibility pages.
- [ ] Run focused snapshot, route, safety, link, copy, architecture, and surface-budget tests through `./capell`.
- [ ] Run `./capell npm run build`, reseed, run `capell:marketing-cms-audit`, and clear affected caches.
- [ ] Browser-QA mobile/desktop, light/dark, keyboard, deferred fragments, above-fold CSS, and cache-disabled variants.
- [ ] Inspect anonymous HTML for editor/package/internal/model/selector/permission/signed-URL leakage.
- [ ] Commit the marketing content and generated build files without unrelated app changes.

## Task 9: Cross-repository completion and release audit

- [ ] Search all three repositories for removed DTOs, contracts, builders, Mix support, dynamic optimizer probing, vendor npm/build types, unsafe editor URLs, and stale docs.
- [ ] Run Core focused suites followed by one fresh `composer preflight`.
- [ ] Run companion focused suites followed by one fresh `composer preflight`.
- [ ] In the app, run `./capell composer-local dump-autoload`, package discovery, Doctor, marketing audits, build, and public route/browser checks against the local overlay.
- [ ] Map every specification bullet and named test to authoritative source, test output, rendered output, or diagnostic evidence; treat missing evidence as incomplete work.
- [ ] Confirm each repository status contains only intended committed work and record commit hashes and verification commands.
