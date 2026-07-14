# Developer and Editor Product Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After the release-confidence gate, turn Capell's extension contracts, publish readiness, operations diagnostics, install review, and multilingual accessibility into deliberate product surfaces.

**Architecture:** Core publishes executable extension-surface metadata and a reusable contract test kit. Admin composes readiness and operations data through Actions/DTOs while Filament remains presentation-only. Marketplace presents dependency/impact evidence from the authoritative install request. Publishing Studio continues to own advanced collaboration.

**Tech Stack:** PHP 8.4+, Laravel, Filament 4, Spatie Laravel Data, Pest architecture snapshots, Markdown/JSON catalogs.

---

## Start condition

Begin this plan only after Plan 5's release harness exists and all Plan 1–4 product contracts are permanent required jobs. Feature work may proceed in isolated branches, but no result from this plan can waive a failed release gate.

### Task 1: Define extension-surface stability metadata

**Files:**
- Create: `packages/core/src/Enums/Extensions/ExtensionSurfaceStability.php`
- Create: `packages/core/src/Data/Extensions/ExtensionSurfaceCatalogEntryData.php`
- Create: `packages/core/src/Attributes/ExtensionSurfaceContract.php`
- Create: `packages/core/src/Actions/Extensions/BuildExtensionSurfaceCatalogAction.php`
- Modify: `packages/core/src/Actions/Extensions/BuildExtensionContractRegistryAction.php`
- Test: `packages/core/tests/Unit/Actions/Extensions/BuildExtensionSurfaceCatalogActionTest.php`

- [ ] **Step 1: Write failing catalog tests** for contracts, facades, DTOs, events, tagged services, configuration keys, render hooks, duplicate IDs, missing owner, and missing stability.

- [ ] **Step 2: Define stability values** as `stable`, `experimental`, and `internal`. Each catalog entry requires stable ID, kind, PHP/config/hook identifier, owner package, stability, introduced version, and summary.

- [ ] **Step 3: Build from executable sources**: explicit PHP attributes/registry contributions for code surfaces and a small typed registry for config keys/render hooks. Do not infer stability from namespace names.

- [ ] **Step 4: Mark current public candidates** conservatively: surfaces explicitly intended for companion packages may be stable/experimental; implementation helpers remain internal. Stable status requires a direct contract test.

- [ ] **Step 5: Run and commit**

  Run: `vendor/bin/pest packages/core/tests/Unit/Actions/Extensions/BuildExtensionSurfaceCatalogActionTest.php`

  ```bash
  git add packages/core/src packages/core/tests/Unit/Actions/Extensions
  git commit -m "feat(core): catalog extension surface stability"
  ```

### Task 2: Publish and verify the extension-surface catalog

**Files:**
- Create: `scripts/build-extension-surface-catalog.php`
- Create: `docs/packages/extension-surface-catalog.json`
- Create: `docs/packages/extension-surface-catalog.md`
- Modify: `docs/packages/extension-point-api-reference.md`
- Modify: `docs/packages/extension-surface-vocabulary.md`
- Create: `tests/Unit/ExtensionSurfaceCatalogContractTest.php`

- [ ] **Step 1: Add a deterministic generation test** that fails when generated JSON/Markdown differs from runtime catalog data, a stable entry has no contract test ID, or docs refer to an unknown surface.

- [ ] **Step 2: Generate both artifacts** sorted by owner/kind/ID. Markdown is human navigation; JSON is the machine source for later compatibility snapshots.

- [ ] **Step 3: Add `composer check:extension-surfaces`** and include it in non-mutating preflight.

- [ ] **Step 4: Run and commit**

  Run: `php scripts/build-extension-surface-catalog.php --check && vendor/bin/pest tests/Unit/ExtensionSurfaceCatalogContractTest.php`

  ```bash
  git add scripts composer.json docs/packages tests/Unit/ExtensionSurfaceCatalogContractTest.php
  git commit -m "docs(platform): publish extension surface catalog"
  ```

### Task 3: Ship a companion-package contract test kit

**Files:**
- Modify: `packages/core/src/Testing/ExtensionTestHarness.php`
- Create: `packages/core/src/Testing/Contracts/CompanionPackageContractSuite.php`
- Create: `packages/core/src/Testing/Data/CompanionPackageContractData.php`
- Create: `packages/core/src/Testing/Assertions/AssertsExtensionManifest.php`
- Create: `packages/core/src/Testing/Assertions/AssertsPackageLifecycle.php`
- Create: `packages/core/src/Testing/Assertions/AssertsPublicOutputSafety.php`
- Create: `packages/core/src/Testing/Assertions/AssertsCacheInvalidation.php`
- Test: `packages/core/tests/Feature/Testing/CompanionPackageContractSuiteTest.php`

- [ ] **Step 1: Create fixture packages** with one valid package and one failure for provider boot, manifest dependency direction, migration discovery, install/upgrade, authorization, cache invalidation, and public-output leakage.

- [ ] **Step 2: Implement an opt-in suite** configured by a typed DTO containing package root, manifest, provider, migrations, lifecycle Actions, protected resources, cache fixtures, and public render callback.

- [ ] **Step 3: Produce actionable failures** containing contract ID and owning fixture path. The suite must not require the full curated monorepo to run a single package.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/core/tests/Feature/Testing/CompanionPackageContractSuiteTest.php`

  ```bash
  git add packages/core/src/Testing packages/core/tests/Feature/Testing
  git commit -m "feat(testing): add companion package contract suite"
  ```

### Task 4: Adopt the test kit in representative companion packages

**Files:**
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/tests/Pest.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/tests/Contract/PackageContractTest.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/publishing-studio/tests/Pest.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/publishing-studio/tests/Contract/PackageContractTest.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/tests/Arch/CuratedPackageContractCoverageTest.php`

- [ ] **Step 1: Adopt in two contrasting packages**: Layout Builder for public rendering/cache/migrations and Publishing Studio for authorization/workflow/lifecycle.

- [ ] **Step 2: Add curated coverage metadata** so every first-party curated package declares either contract-suite coverage or an explicit no-runtime-surface reason.

- [ ] **Step 3: Run package suites independently**

  Run: `vendor/bin/pest packages/layout-builder/tests/Contract packages/publishing-studio/tests/Contract tests/Arch/CuratedPackageContractCoverageTest.php`

- [ ] **Step 4: Commit in the companion repository**

  ```bash
  git add packages/layout-builder packages/publishing-studio tests/Arch
  git commit -m "test(packages): adopt shared extension contracts"
  ```

### Task 5: Prepare post-release stable API snapshots

**Files:**
- Create: `scripts/check-stable-extension-api.php`
- Create: `tests/Unit/StableExtensionApiSnapshotTest.php`
- Create: `docs/packages/stable-extension-api-baseline.json`
- Modify: `docs/packages/extension-surface-catalog.md`

- [ ] **Step 1: Write snapshot tests** for removed class, changed public signature, changed manifest requirement, package constraint drift, omitted migration, and renamed config key.

- [ ] **Step 2: Generate the baseline** only from catalog entries marked stable. Before the first public tag, store `status: pending-first-public-release` and make the check report drift without claiming compatibility. The release process flips the status and pins the first baseline after the public tag.

- [ ] **Step 3: Require an explicit compatibility decision file** for stable drift after activation; do not silently update baselines in formatting or fix commands.

- [ ] **Step 4: Run and commit**

  Run: `php scripts/check-stable-extension-api.php --check && vendor/bin/pest tests/Unit/StableExtensionApiSnapshotTest.php`

  ```bash
  git add scripts docs/packages tests/Unit/StableExtensionApiSnapshotTest.php composer.json
  git commit -m "feat(platform): prepare stable api compatibility snapshots"
  ```

### Task 6: Make publish readiness the primary editor workflow

**Files:**
- Modify: `packages/admin/src/Actions/Reports/BuildPublishingReadinessReportAction.php`
- Create: `packages/admin/src/Data/Publishing/PublishReadinessData.php`
- Create: `packages/admin/src/Actions/Publishing/BuildPublishReadinessAction.php`
- Modify: `packages/admin/src/Filament/Livewire/PublishStatusPanel.php`
- Modify: `packages/admin/src/Actions/Dashboard/BuildPublishingWorkflowEntryAction.php`
- Modify: `packages/admin/resources/lang/en/reports.php`
- Test: `packages/admin/tests/Feature/Publishing/PublishReadinessWorkflowTest.php`

- [ ] **Step 1: Write failing editor scenarios** showing current state, blockers, scheduled transitions, public effect, and the typed preview from Plan 2 for a single record and mixed bulk selection.

- [ ] **Step 2: Build one readiness DTO** from publication state, blocking check IDs, scheduled transition, current public eligibility, and allowed transition requests. The report, panel, and dashboard consume this DTO.

- [ ] **Step 3: Keep the UI thin**. Filament renders translated status and calls transition/preview Actions; it does not inspect dates or run readiness queries itself.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/admin/tests/Feature/Publishing/PublishReadinessWorkflowTest.php packages/admin/tests/Feature/Reports/PublishingReadinessReportTest.php packages/admin/tests/Feature/Filament/Livewire/PublishStatusPanelTest.php`

  ```bash
  git add packages/admin
  git commit -m "feat(admin): center publishing on readiness"
  ```

### Task 7: Expand the Operations Center

**Files:**
- Modify: `packages/admin/src/Actions/Reports/BuildDemoInstallHealthReportAction.php`
- Create: `packages/admin/src/Actions/Diagnostics/BuildOperationsCenterAction.php`
- Create: `packages/admin/src/Data/Diagnostics/OperationsCenterData.php`
- Modify: `packages/admin/src/Filament/Pages/Reports/DemoInstallHealthReport.php`
- Test: `packages/admin/tests/Feature/Reports/OperationsCenterCoverageTest.php`

- [ ] **Step 1: Add failing category coverage** for queue, cache, storage, package compatibility, schema integrity, admin access, and public-route health. Assert stable finding IDs, evidence, remediation, generated time, and rerun behavior.

- [ ] **Step 2: Compose existing Doctor/report Actions** into category groups; do not reproduce checks in the Filament page.

- [ ] **Step 3: Add safe remediation links/actions** only where an existing authorized Action exists. Otherwise show exact CLI/manual remediation text.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/admin/tests/Feature/Reports/OperationsCenterCoverageTest.php packages/admin/tests/Feature/Reports/OperationsCenterRerunTest.php`

  ```bash
  git add packages/admin
  git commit -m "feat(admin): expand operations center coverage"
  ```

### Task 8: Show complete extension install impact

**Files:**
- Modify: `packages/core/src/Actions/Packages/BuildExtensionInstallImpactAction.php`
- Modify: `packages/core/src/Data/ExtensionInstallImpactData.php`
- Modify: `packages/marketplace/resources/views/components/install-review.blade.php`
- Modify: `packages/marketplace/resources/lang/en/marketplace.php`
- Test: `packages/marketplace/tests/Feature/Filament/MarketplaceInstallImpactReviewTest.php`

- [ ] **Step 1: Add a dependency graph fixture** with direct/transitive packages, mixed maturity, entitlement, migrations, routes, scheduled jobs, storage, permissions, and package change operations.

- [ ] **Step 2: Extend typed impact data** to expose every node and its maturity, entitlement, operational contributions, version change, and reason for inclusion.

- [ ] **Step 3: Render grouped impact** from the DTO and preserve the exact beta dependency/acknowledgement behavior from Plan 3.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/marketplace/tests/Feature/Filament/MarketplaceInstallImpactReviewTest.php packages/marketplace/tests/Feature/Filament/MarketplaceExtensionDetailPageTest.php`

  ```bash
  git add packages/core packages/marketplace
  git commit -m "feat(marketplace): review complete extension impact"
  ```

### Task 9: Add multilingual accessibility readiness

**Files:**
- Create: `packages/admin/src/Actions/Reports/BuildAccessibilityReadinessReportAction.php`
- Create: `packages/admin/src/Data/Reports/AccessibilityReadinessFindingData.php`
- Create: `packages/admin/src/Filament/Pages/Reports/AccessibilityReadinessReport.php`
- Modify: `packages/admin/src/Support/Reports/ReportRegistry.php`
- Modify: `packages/admin/resources/lang/en/reports.php`
- Create: `packages/admin/tests/Feature/Reports/AccessibilityReadinessReportTest.php`

- [ ] **Step 1: Write failing fixtures** for missing required-language page translation, missing localized media alt/caption/credit, explicit decorative image, absent decorative intent, broken localized URL, and complete multilingual content.

- [ ] **Step 2: Build findings through one Action** using configured required site languages and hydrated ownership relationships. Decorative images pass without alt only when decorative intent is explicit.

- [ ] **Step 3: Register the report** with translated navigation, site scoping, evidence, and remediation links to the owning page/media record.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/admin/tests/Feature/Reports/AccessibilityReadinessReportTest.php packages/admin/tests/Unit/Reports/ReportRegistryTest.php`

  ```bash
  git add packages/admin
  git commit -m "feat(admin): report multilingual accessibility readiness"
  ```

### Task 10: Preserve Publishing Studio ownership

**Files:**
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/publishing-studio/src/Providers/PublishingStudioServiceProvider.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/publishing-studio/tests/Arch/PublishingStudioBoundaryTest.php`
- Modify: `docs/development/package-boundaries.md`
- Create: `tests/Unit/PublishingStudioBoundaryContractTest.php`

- [ ] **Step 1: Add architecture failures** if Core/Admin define approvals, assignments, release workspaces, comments, or advanced collaboration implementations rather than contracts/contributions.

- [ ] **Step 2: Verify Publishing Studio contributes** those workflows through existing registries/tags and consumes Core publication/readiness types without overriding foundation invariants.

- [ ] **Step 3: Run cross-repository boundary tests**

  Run foundation: `vendor/bin/pest tests/Unit/PublishingStudioBoundaryContractTest.php`

  Run companion: `vendor/bin/pest packages/publishing-studio/tests/Arch/PublishingStudioBoundaryTest.php`

- [ ] **Step 4: Commit documentation/contract tests** in their owning repositories.

## Exit gate

- Every extension surface is catalogued as stable, experimental, or internal from executable metadata.
- Representative companion packages pass the reusable contract suite independently.
- Stable API snapshot enforcement is prepared but compatibility is activated only after the first public release.
- Editors see one coherent publish-readiness workflow and accurate previews.
- Operators see actionable, rerunnable Operations Center findings.
- Install review covers direct/transitive maturity, entitlement, impact, and package changes.
- Multilingual accessibility findings cover translations, localized media intent, broken URLs, and required-language gaps.
- Advanced collaboration remains in Publishing Studio.
