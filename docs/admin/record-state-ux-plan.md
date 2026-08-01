# Admin Record State UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make page, layout, media, Layout Builder widget, and widget-asset availability, publication, usage, integrity, and bounded relationship counts visible and consistent throughout the Filament admin.

**Architecture:** Keep domain fact resolution in the owning package and put only the immutable state presentation contract and Filament adapters in `packages/admin`. Compose availability, publication, usage, integrity, and relationship-count facts as separate ordered chips; preserve `PageTableStatusResolver` compatibility. Every surface query preloads its facts so rendering performs no database queries.

**Tech Stack:** PHP 8.4, Laravel, Filament, Spatie Laravel Data, Pest, Blade, Core/Admin packages, and the Layout Builder companion package.

---

## File Map

Core Admin files are changed in `/Users/ben/Sites/packages/capell/capell-4`.
Layout Builder files are changed in `/Users/ben/Sites/packages/capell/capell-packages-4`.

- Create `packages/admin/src/Data/RecordStateData.php`: immutable state chip data with stable key, translated labels, description, icon, colour, and priority.
- Create `packages/admin/src/Data/RecordRelationshipCountData.php`: bounded relationship-count data with label, count, link, and authority wording.
- Create `packages/admin/src/Support/RecordState/RecordStateComposer.php`: deterministic ordering, exceptional-state filtering, and surface-specific chip composition.
- Create `packages/admin/resources/views/components/record-state-summary.blade.php`: render visible, accessible state text for headers/cards.
- Modify `packages/admin/src/Filament/Concerns/HasCustomSelectOption.php` and `packages/admin/resources/views/components/forms/select-option.blade.php`: accept optional state/count metadata without breaking existing callers.
- Create page availability and relationship resolvers under `packages/admin/src/Actions/Pages` and `packages/admin/src/Actions/Layouts`, with their typed data classes under `packages/admin/src/Data/Pages` and `packages/admin/src/Data/Layouts`.
- Create typed page availability and relationship table columns under `packages/admin/src/Filament/Components/Tables/Columns/Page` and `packages/admin/src/Filament/Components/Tables/Columns`.
- Modify `packages/admin/src/Filament/Resources/Pages/Pages/EditPage.php`, `PagesTable.php`, `PageResource.php`, `PageSelect.php`, and `PageMorphToOptionSelect.php`.
- Modify `packages/admin/src/Filament/Resources/Layouts/Pages/EditLayout.php`, `LayoutsTable.php`, `LayoutResource.php`, `LayoutSelect.php`, and `packages/admin/src/Support/Layouts/LayoutCardData.php`.
- Modify `packages/admin/src/Filament/Resources/Media/Tables/MediaTable.php` and its media tests.
- Add focused tests beside each changed Admin surface under `packages/admin/tests/Feature/Filament` and unit tests under `packages/admin/tests/Unit` where the repository's test layout supports them.
- Modify Layout Builder `packages/layout-builder/src/Filament/Resources/Widgets/Pages/EditWidget.php`, `WidgetsTable.php`, `WidgetSelectionTable.php`, `WidgetAssetsTable.php`, `LayoutsRelationManager.php`, `WidgetAssetsRelationManager.php`, `packages/layout-builder/src/Models/Widget.php`, `packages/layout-builder/src/Models/WidgetAsset.php`, and `composer.json`.
- Add Layout Builder tests under `packages/layout-builder/tests/Feature/Filament` and a dependency contract test for the minimum Admin version.

### Task 1: Establish the immutable Admin presentation contract

**Files:**

- Create: `packages/admin/src/Data/RecordStateData.php`
- Create: `packages/admin/src/Data/RecordRelationshipCountData.php`
- Create: `packages/admin/src/Support/RecordState/RecordStateComposer.php`
- Test: `packages/admin/tests/Unit/Support/RecordState/RecordStateComposerTest.php`

- [ ] **Step 1: Write failing contract tests**

  Define tests for a state with a stable key, translated label, short label,
  description, Filament colour, Heroicon-compatible icon, and priority. Assert
  that the composer orders availability before publication before usage, removes
  routine positive states when a compact surface requests exceptional-only
  output, and retains combinations such as `no_active_url` plus `scheduled`.

- [ ] **Step 2: Run the focused test and confirm failure**

  Run:

  ```bash
  ./vendor/bin/pest packages/admin/tests/Unit/Support/RecordState/RecordStateComposerTest.php --configuration=phpunit.xml
  ```

  Expected: failure because the new data and composer classes do not exist.

- [ ] **Step 3: Implement the minimal typed contract**

  `RecordStateData` must expose `public readonly string $key`, `$label`,
  `?string $shortLabel`, `?string $description`,
  `BackedEnum|string|Htmlable|null $icon`, `string $color`, and `int $priority`.
  `RecordRelationshipCountData` must expose a stable key, translated label,
  integer count, `bool $authoritative`, and nullable URL. The composer accepts
  `list<RecordStateData>` and returns the ordered list without querying models.

- [ ] **Step 4: Run the focused test and confirm success**

  Run the command from Step 2. Expected: all contract tests pass.

- [ ] **Step 5: Commit the contract**

  ```bash
  git add packages/admin/src/Data packages/admin/src/Support/RecordState packages/admin/tests/Unit/Support/RecordState/RecordStateComposerTest.php
  git commit -m "feat(admin): add composable record state contract"
  ```

### Task 2: Add shared state and relationship rendering

**Files:**

- Create: `packages/admin/resources/views/components/record-state-summary.blade.php`
- Create: `packages/admin/resources/views/components/record-state-chip.blade.php`
- Modify: `packages/admin/src/Filament/Concerns/HasCustomSelectOption.php`
- Modify: `packages/admin/resources/views/components/forms/select-option.blade.php`
- Test: `packages/admin/tests/Feature/Filament/Components/RecordStatePresentationTest.php`

- [ ] **Step 1: Write failing rendering tests**

  Render the summary with `no_active_url` and `scheduled` states and assert
  visible labels, descriptions, icon markup, and accessible text. Render a
  select option with a disabled state and relationship count and assert escaped
  label text, state text, count text, and no output when optional metadata is
  absent.

- [ ] **Step 2: Run the focused test and confirm failure**

  ```bash
  ./vendor/bin/pest packages/admin/tests/Feature/Filament/Components/RecordStatePresentationTest.php --configuration=phpunit.xml
  ```

  Expected: failure because the shared views and optional select payload do not
  exist.

- [ ] **Step 3: Implement accessible shared views**

  Add visible text for every state, use icons and colour only as supplements,
  escape labels and descriptions, add accessible labels for compact icons, and
  preserve the existing `count`, `description`, `image`, `prefix`, `inline`,
  and `size` select-option props. Add an optional `states` list and optional
  relationship-count list without changing existing output when omitted.

- [ ] **Step 4: Run the focused test and inspect HTML**

  Run the command from Step 2 and inspect the generated HTML for light/dark
  classes and escaped state text. Expected: all tests pass.

- [ ] **Step 5: Commit the shared presentation layer**

  ```bash
  git add packages/admin/src/Data packages/admin/src/Support/RecordState packages/admin/src/Filament/Concerns/HasCustomSelectOption.php packages/admin/resources/views packages/admin/tests/Feature/Filament/Components/RecordStatePresentationTest.php
  git commit -m "feat(admin): render accessible record state summaries"
  ```

### Task 3: Resolve page availability and bounded page counts

**Files:**

- Create: `packages/admin/src/Actions/Pages/ResolvePageAvailabilityStateAction.php`
- Create: `packages/admin/src/Actions/Pages/BuildPageRelationshipCountsAction.php`
- Create: `packages/admin/src/Data/Pages/PageAvailabilityData.php`
- Create: `packages/admin/src/Data/Pages/PageRelationshipCountsData.php`
- Create: `packages/admin/src/Filament/Components/Tables/Columns/Page/PageAvailabilityColumn.php`
- Test: `packages/admin/tests/Feature/Actions/Pages/ResolvePageAvailabilityStateActionTest.php`
- Test: `packages/admin/tests/Feature/Actions/Pages/BuildPageRelationshipCountsActionTest.php`

- [ ] **Step 1: Write failing fact-resolution tests**

  Freeze time and cover pages with all URLs enabled, all URLs disabled, mixed
  URL states, no URLs, multiple languages, inaccessible sites, and scheduled
  publication. Assert that availability says `No active URL` or `Some URLs
  disabled` without inventing a `Page.status`. Assert child and URL counts are
  returned from preloaded relations or query aliases.

- [ ] **Step 2: Run the focused tests and confirm failure**

  ```bash
  ./vendor/bin/pest packages/admin/tests/Feature/Actions/Pages/ResolvePageAvailabilityStateActionTest.php packages/admin/tests/Feature/Actions/Pages/BuildPageRelationshipCountsActionTest.php --configuration=phpunit.xml
  ```

  Expected: failure because the resolvers do not exist.

- [ ] **Step 3: Implement page facts without database work in presentation**

  Resolve URL availability with site/language scope and preserve deleted or
  inaccessible records as distinct facts. Extend the existing page table query
  to preload children and URL aggregates only when the relevant surface needs
  them. Keep `PageTableStatusResolver::modifyQuery()` and `resolve()` untouched.

- [ ] **Step 4: Run the focused tests and confirm success**

  Run the command from Step 2. Expected: all page fact tests pass, including
  frozen-time and mixed-language cases.

- [ ] **Step 5: Commit page fact resolution**

  ```bash
  git add packages/admin/src/Actions/Pages packages/admin/src/Data/Pages packages/admin/src/Filament/Components/Tables/Columns/Page packages/admin/tests/Feature/Actions/Pages
  git commit -m "feat(admin): resolve page availability and counts"
  ```

### Task 4: Adopt state and counts on page surfaces

**Files:**

- Modify: `packages/admin/src/Filament/Resources/Pages/Pages/EditPage.php`
- Modify: `packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php`
- Modify: `packages/admin/src/Filament/Resources/Pages/PageResource.php`
- Modify: `packages/admin/src/Filament/Components/Forms/PageSelect.php`
- Modify: `packages/admin/src/Filament/Components/Forms/PageMorphToOptionSelect.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Page/Pages/EditPageTest.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Page/Pages/ListPagesTest.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Page/PageResourceTest.php`
- Test: `packages/admin/tests/Feature/Filament/Components/PageSelectTest.php`

- [ ] **Step 1: Add failing page-surface assertions**

  Assert that edit headers show availability before publication and include the
  scheduled date/time. Assert that list rows show exceptional availability,
  relationship counts are links or compact metadata, global search includes a
  compact exceptional summary, and an already-selected unavailable page still
  renders in `PageSelect` without a per-option query.

- [ ] **Step 2: Run the focused page tests and confirm failure**

  ```bash
  ./vendor/bin/pest packages/admin/tests/Feature/Filament/Resources/Page/Pages/EditPageTest.php packages/admin/tests/Feature/Filament/Resources/Page/Pages/ListPagesTest.php packages/admin/tests/Feature/Filament/Resources/Page/PageResourceTest.php packages/admin/tests/Feature/Filament/Components/PageSelectTest.php --configuration=phpunit.xml
  ```

  Expected: the new state text and counts are absent.

- [ ] **Step 3: Implement page header, table, search, and selector adapters**

  Compose existing publication data with page availability. Use the shared
  select-option view for labels such as `Homepage — No active URL`, while
  retaining current query eligibility rules. Add exceptional filters only where
  the table query can support them without changing page scope or extender
  behaviour.

- [ ] **Step 4: Run the focused page tests and confirm success**

  Run the command from Step 2. Expected: all page surface tests pass and the
  query-count assertions show no per-option or per-row state queries.

- [ ] **Step 5: Commit the page surfaces**

  ```bash
  git add packages/admin/src/Filament/Resources/Pages packages/admin/src/Filament/Components/Forms/PageSelect.php packages/admin/src/Filament/Components/Forms/PageMorphToOptionSelect.php packages/admin/tests/Feature/Filament/Resources/Page packages/admin/tests/Feature/Filament/Components/PageSelectTest.php
  git commit -m "feat(admin): surface page availability in Filament"
  ```

### Task 5: Resolve layout usage and adopt layout surfaces

**Files:**

- Create: `packages/admin/src/Actions/Layouts/BuildLayoutRelationshipCountsAction.php`
- Create: `packages/admin/src/Data/Layouts/LayoutRelationshipCountsData.php`
- Modify: `packages/admin/src/Filament/Resources/Layouts/Pages/EditLayout.php`
- Modify: `packages/admin/src/Filament/Resources/Layouts/Tables/LayoutsTable.php`
- Modify: `packages/admin/src/Filament/Resources/Layouts/LayoutResource.php`
- Modify: `packages/admin/src/Filament/Components/Forms/Page/LayoutSelect.php`
- Modify: `packages/admin/src/Support/Layouts/LayoutCardData.php`
- Modify: `packages/admin/resources/views/filament/resources/layouts/layout-card.blade.php`
- Test: `packages/admin/tests/Feature/Actions/Layouts/BuildLayoutRelationshipCountsActionTest.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Layout/Pages/EditLayoutTest.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Layout/Pages/ListLayoutsTest.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Layout/LayoutResourceTest.php`
- Test: `packages/admin/tests/Feature/Filament/Components/LayoutSelectTest.php`

- [ ] **Step 1: Write failing layout count tests**

  Cover zero-page layouts, layouts used by multiple registered page variations,
  actor-scoped sites, containers, and configured widgets. Assert that the
  authoritative count is shared by list, header, and selector and that an
  actor-scoped zero uses `No accessible pages` rather than `Unused`.

- [ ] **Step 2: Run the focused layout tests and confirm failure**

  ```bash
  ./vendor/bin/pest packages/admin/tests/Feature/Actions/Layouts/BuildLayoutRelationshipCountsActionTest.php packages/admin/tests/Feature/Filament/Resources/Layout/Pages/EditLayoutTest.php packages/admin/tests/Feature/Filament/Resources/Layout/Pages/ListLayoutsTest.php packages/admin/tests/Feature/Filament/Resources/Layout/LayoutResourceTest.php packages/admin/tests/Feature/Filament/Components/LayoutSelectTest.php --configuration=phpunit.xml
  ```

  Expected: the new relationship summary and shared variation-aware count
  assertions fail.

- [ ] **Step 3: Implement one variation-aware layout count path**

  Extract the existing registered-page-variation SQL/count logic into the
  Admin-owned action/helper. Return page, container, and widget counts only when
  their source is loaded or safely queryable. Remove the per-variation-per-row
  count loop from card/table formatting; usage links consume preloaded counts.

- [ ] **Step 4: Add layout headers, cards, lists, search, and selectors**

  Add a persistent edit-header summary, image-area exceptional badges, compact
  page-use counts, and select-option state text. Keep existing card actions,
  checkbox positioning, site scope, and preview rendering intact.

- [ ] **Step 5: Run the focused layout tests and confirm success**

  Run the command from Step 2. Expected: all layout tests pass with no N+1
  queries and the card remains usable at existing breakpoints.

- [ ] **Step 6: Commit the layout surfaces**

  ```bash
  git add packages/admin/src/Actions/Layouts packages/admin/src/Data/Layouts packages/admin/src/Filament/Resources/Layouts packages/admin/src/Filament/Components/Forms/Page/LayoutSelect.php packages/admin/src/Support/Layouts packages/admin/resources/views/filament/resources/layouts packages/admin/tests/Feature/Actions/Layouts packages/admin/tests/Feature/Filament/Resources/Layout packages/admin/tests/Feature/Filament/Components/LayoutSelectTest.php
  git commit -m "feat(admin): surface layout availability and usage"
  ```

### Task 6: Make media usage explicit

**Files:**

- Modify: `packages/admin/src/Filament/Resources/Media/Tables/MediaTable.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Media/Pages/ListMediaTest.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Media/Pages/EditMediaTest.php`

- [ ] **Step 1: Write failing media usage tests**

  Assert that a zero attachment count renders `Unused` only for the supported
  exhaustive attachment contract, that non-zero counts link to the usage view,
  and that a usage filter and sort preserve MediaScope.

- [ ] **Step 2: Run the focused media tests and confirm failure**

  ```bash
  ./vendor/bin/pest packages/admin/tests/Feature/Filament/Resources/Media/Pages/ListMediaTest.php packages/admin/tests/Feature/Filament/Resources/Media/Pages/EditMediaTest.php --configuration=phpunit.xml
  ```

  Expected: zero usage is currently only a neutral numeric value.

- [ ] **Step 3: Implement explicit usage semantics**

  Reuse the existing `usage_count` accessor/query path, add the filter and
  visible wording, and keep incomplete rich-text/package-owned references out
  of the authoritative claim.

- [ ] **Step 4: Run tests and commit**

  Run the command from Step 2. Expected: all media tests pass.

  ```bash
  git add packages/admin/src/Filament/Resources/Media/Tables/MediaTable.php packages/admin/tests/Feature/Filament/Resources/Media
  git commit -m "feat(admin): make media usage explicit"
  ```

### Task 7: Adopt the contract in Layout Builder

**Files:**

- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/composer.json`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/src/Filament/Resources/Widgets/Pages/EditWidget.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/src/Filament/Resources/Widgets/Tables/WidgetsTable.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/src/Filament/Resources/Widgets/Tables/WidgetSelectionTable.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/src/Filament/Resources/Widgets/Tables/WidgetAssetsTable.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/src/Filament/Resources/Widgets/RelationManagers/LayoutsRelationManager.php`
- Modify: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/src/Filament/Resources/Widgets/RelationManagers/WidgetAssetsRelationManager.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/src/Actions/BuildWidgetRelationshipCountsAction.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/src/Data/WidgetRelationshipCountsData.php`
- Test: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/tests/Feature/Filament/Resources/Widget/Pages/EditWidgetTest.php`
- Create: `/Users/ben/Sites/packages/capell/capell-packages-4/packages/layout-builder/tests/Feature/Filament/WidgetRecordStateContractTest.php`

- [ ] **Step 1: Write failing package adoption tests**

  Cover disabled/unavailable widgets, direct and nested widget placement,
  composed-widget and preset references, positive layout/asset counts, valid
  layout-level default assets, and broken polymorphic targets. Assert that
  `Unused` is emitted only from a complete reference query and that missing
  references are `Broken reference` or `Orphaned`.

- [ ] **Step 2: Update the package dependency before implementation**

  Raise `capell-app/admin` from `^1.0.10` to the first Admin release that
  contains the presentation contract, update the package lock/overlay, and add
  a Composer contract test that fails when the required Admin API is absent.

- [ ] **Step 3: Implement package-owned usage and integrity facts**

  Build additive query enrichment for widgets and widget assets. Include every
  Layout Builder-owned reference form required by the tests; do not infer
  orphaning from a nullable pageable field. Return preloaded relationship and
  integrity counts to the shared Admin rendering components.

- [ ] **Step 4: Adopt headers, tables, pickers, and relation managers**

  Add compact state summaries to `EditWidget`, exceptional columns and counts
  to the widget tables, state-aware selection options, and integrity labels to
  widget-asset tables. Preserve existing bulk layout actions and permissions.

- [ ] **Step 5: Run focused package verification and commit**

  ```bash
  ./vendor/bin/pest packages/layout-builder/tests/Feature/Filament/Resources/Widget/Pages/EditWidgetTest.php packages/layout-builder/tests/Feature/Filament/WidgetRecordStateContractTest.php --configuration=phpunit.xml
  composer --working-dir=/Users/ben/Sites/packages/capell/capell-packages-4 lint
  ```

  Expected: focused tests, formatting, and the dependency contract pass.

  ```bash
  git -C /Users/ben/Sites/packages/capell/capell-packages-4 add packages/layout-builder/composer.json packages/layout-builder/src packages/layout-builder/tests
  git -C /Users/ben/Sites/packages/capell/capell-packages-4 commit -m "feat(layout-builder): surface widget usage states"
  ```

### Task 8: Verify the integrated slice and update project evidence

**Files:**

- Modify: `/Users/ben/Sites/internal-ledger/projects/capell/TODO.md` only through the ledger workflow
- Verify: all files changed in Tasks 1–7

- [ ] **Step 1: Run focused Admin verification**

  ```bash
  ./vendor/bin/pest packages/admin/tests/Unit/Support/RecordState packages/admin/tests/Feature/Actions/Pages packages/admin/tests/Feature/Actions/Layouts packages/admin/tests/Feature/Filament/Components packages/admin/tests/Feature/Filament/Resources/Page packages/admin/tests/Feature/Filament/Resources/Layout packages/admin/tests/Feature/Filament/Resources/Media --configuration=phpunit.xml
  ```

  Expected: all focused state, count, page, layout, media, header, card, search,
  and selector tests pass.

- [ ] **Step 2: Run package focused verification**

  ```bash
  ./vendor/bin/pest packages/layout-builder/tests/Feature/Filament --configuration=phpunit.xml
  ```

  Expected: Layout Builder Filament tests pass against the declared Admin
  version.

- [ ] **Step 3: Run formatting and static analysis for changed repositories**

  ```bash
  composer lint
  composer analyze
  composer -d /Users/ben/Sites/packages/capell/capell-packages-4 lint
  ```

  Expected: changed-code formatting and PHPStan checks pass. Full repository
  suites remain a separate evidence boundary if they are not run.

- [ ] **Step 4: Run documentation and diff hygiene checks**

  ```bash
  php scripts/check-docs-links.php
  git diff --check main...HEAD
  ```

  Expected: documentation links resolve and the diff contains no whitespace
  errors or unrelated generated files.

- [ ] **Step 5: Reconcile CAP-0080 in the internal ledger**

  Record the exact Core and Layout Builder commits, focused test commands and
  results, any full-suite boundary, and remaining package-adoption inventory.
  Do not claim hosted CI, publication, release, deployment, or live/browser
  evidence unless those gates actually ran.

- [ ] **Step 6: Commit only the final focused verification changes**

  ```bash
  git status --short
  git diff --check
  git commit -m "test(admin): verify record state UX integration"
  ```

  Expected: the final commit contains only approved Admin/Layout Builder state
  work and its tests.

## Self-Review

- State model coverage: Tasks 1–4 cover separate availability and publication
  chips plus selected-value safety; Tasks 5–7 cover layout, media, widget, and
  widget-asset usage/integrity.
- Count coverage: Tasks 3, 5, 6, and 7 cover page URLs/children, layout pages,
  containers/widgets, widget layouts/assets, widget-asset integrity, and media
  attachments. Each count is preloaded or explicitly omitted when it cannot be
  authoritative.
- Package boundaries: Core remains untouched for UI; Admin owns presentation;
  Layout Builder owns widget facts and raises its Admin dependency.
- Compatibility: Existing `PageTableStatusResolver` signatures and select
  option props remain stable.
- Query safety: State and count resolution is tested independently and no
  rendering step is allowed to query.
- Accessibility: Shared components require visible text, escaped labels,
  accessible compact icons, and light/dark contrast assertions.
- Scope: Later companion adoption inventory remains outside the first slice.
- Placeholder scan: no unresolved placeholder marker or unspecified
  implementation step remains in this plan.
