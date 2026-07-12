# Frontend Lighthouse Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve Capell's shared LCP image, responsive preload, lazy-loading, and local-font defaults.

**Architecture:** Extend the existing typed media-hint boundary rather than parsing rendered HTML or adding another middleware. Keep media selection in the Action, HTML attributes in Blade, and verify each public-render contract directly.

**Tech Stack:** PHP 8.4, Laravel 12/13, Spatie Laravel Data, Blade, Pest, Lighthouse.

---

### Task 1: Responsive LCP media hints

**Files:**

- Modify: `packages/frontend/src/Data/FrontendMediaHintData.php`
- Modify: `packages/frontend/src/Actions/BuildFrontendMediaHintsAction.php`
- Test: `packages/frontend/tests/Unit/Actions/BuildFrontendMediaHintsActionTest.php`

- [x] **Step 1: Write the failing contract assertions**

Assert that a page image hint exposes its canonical media URL, conversion `srcset`, and `100vw` sizes alongside the existing fallback URL and fetch priority.

- [x] **Step 2: Run the focused action test and confirm it fails**

Run: `vendor/bin/pest packages/frontend/tests/Unit/Actions/BuildFrontendMediaHintsActionTest.php`

- [x] **Step 3: Implement the typed hint fields and conversion source builder**

Add nullable `mediaUrl`, `imageSrcset`, and `imageSizes` constructor properties. Build the source set from responsive media when present or from available Capell conversions using `MediaConversionEnum::defaultDimensionsByConversionValue()`.

- [x] **Step 4: Rerun the focused test**

Run: `vendor/bin/pest packages/frontend/tests/Unit/Actions/BuildFrontendMediaHintsActionTest.php`

### Task 2: Render matching preload and image priority attributes

**Files:**

- Modify: `packages/frontend/src/Actions/PrepareFrontendRenderAction.php`
- Modify: `packages/frontend/resources/views/components/app/head/index.blade.php`
- Modify: `packages/frontend/resources/views/components/media/index.blade.php`
- Modify: `packages/frontend/resources/views/components/logo/index.blade.php`
- Modify: `packages/frontend/resources/views/components/content.blade.php`
- Test: `packages/frontend/tests/Unit/View/AppHeadTest.php`
- Test: `packages/frontend/tests/Feature/MediaComponentMetadataTest.php`

- [x] **Step 1: Add failing head and media assertions**

Cover `imagesrcset`/`imagesizes`, canonical LCP matching, lazy non-LCP media, and preserved explicit eager loading.

- [x] **Step 2: Run the focused view tests and confirm failure**

Run: `vendor/bin/pest packages/frontend/tests/Unit/View/AppHeadTest.php packages/frontend/tests/Feature/MediaComponentMetadataTest.php`

- [x] **Step 3: Implement render behaviour**

Pass the canonical media URL through frontend context, render responsive preload attributes, set known LCP media to eager/high, default other media to lazy, and keep the logo explicitly eager.

- [x] **Step 4: Rerun the focused view tests**

Run: `vendor/bin/pest packages/frontend/tests/Unit/View/AppHeadTest.php packages/frontend/tests/Feature/MediaComponentMetadataTest.php`

### Task 3: Non-blocking local fonts

**Files:**

- Modify: `packages/frontend/resources/views/components/app/head/index.blade.php`
- Test: `packages/frontend/tests/Unit/View/AppHeadTest.php`

- [x] **Step 1: Add a failing local-font assertion**

Render a local theme font and assert its generated `@font-face` includes `font-display: swap`.

- [x] **Step 2: Run the focused head test and confirm failure**

Run: `vendor/bin/pest packages/frontend/tests/Unit/View/AppHeadTest.php`

- [x] **Step 3: Add `font-display: swap` to the generated rule**

Keep all existing sanitised font values unchanged.

- [x] **Step 4: Rerun the focused head test**

Run: `vendor/bin/pest packages/frontend/tests/Unit/View/AppHeadTest.php`

### Task 4: Verification and handoff

**Files:**

- Modify: `docs/performance/critical-asset-optimization.md`

- [x] **Step 1: Document the new defaults**

Explain canonical LCP matching, responsive preload selection, lazy non-LCP media, and local font swap behaviour.

- [x] **Step 2: Run focused Frontend verification**

Run: `vendor/bin/pest packages/frontend/tests/Unit/Actions/BuildFrontendMediaHintsActionTest.php packages/frontend/tests/Unit/View/AppHeadTest.php packages/frontend/tests/Feature/MediaComponentMetadataTest.php packages/frontend/tests/Unit/Actions/BuildPublicPageRenderDataActionTest.php packages/frontend/tests/Unit/Actions/BuildStaticPageArtifactMetadataActionTest.php`

- [x] **Step 3: Run formatting and static checks for changed files**

Run: `vendor/bin/pint --test packages/frontend/src/Data/FrontendMediaHintData.php packages/frontend/src/Actions/BuildFrontendMediaHintsAction.php packages/frontend/src/Actions/PrepareFrontendRenderAction.php packages/frontend/tests/Unit/Actions/BuildFrontendMediaHintsActionTest.php packages/frontend/tests/Unit/View/AppHeadTest.php packages/frontend/tests/Feature/MediaComponentMetadataTest.php`.

Run: `npx prettier --check packages/frontend/resources/views/components/app/head/index.blade.php packages/frontend/resources/views/components/media/index.blade.php packages/frontend/resources/views/components/logo/index.blade.php docs/performance/critical-asset-optimization.md docs/superpowers/specs/2026-07-12-frontend-lighthouse-foundation-design.md docs/superpowers/plans/2026-07-12-frontend-lighthouse-foundation.md`.

- [x] **Step 4: Review and commit**

Confirm `git diff --check`, review the scoped diff, stage only task files, and create a focused implementation commit.
