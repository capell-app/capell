# Admin Record State UX Follow-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make exceptional page, layout, media, widget, and widget-asset records discoverable, actionable, and safe to review in Filament without overstating incomplete usage evidence.

**Architecture:** Reuse the completed Admin record-state contract and have each owning package resolve its own queryable facts in typed Actions/Data. Tables use bounded query predicates and aggregates; headers, selectors, confirmations, and the Layout Builder health widget receive preloaded data and do not query while rendering. `Unused` is emitted only from an authoritative complete usage boundary; all other zero evidence uses a scoped or tracked-use label.

**Tech Stack:** PHP 8.4, Laravel, Filament 4, Spatie Laravel Data, Pest, Blade, Core/Admin packages, and the Layout Builder companion package.

**Closeout:** Implemented and locally verified on 2026-08-02. Core/Admin
focused tests (46 / 297), record-state fixture tests (5 / 37), and full static
analysis pass. Layout Builder focused tests (33 / 555), targeted static
analysis, screenshot-runner JavaScript tests (5 / 5), and two authenticated
integrity captures pass. Full companion analysis remains blocked by the
pre-existing nullable-label finding in `packages/navigation`'s
`NavigationSelect`, outside this slice. Sol final review approved all
task-owned changes.

---

## File Map

Core/Admin changes live in `/Users/ben/Sites/packages/capell/capell-4`.

- Create `packages/admin/src/Data/RecordDeletionImpactData.php`: immutable copy-ready summary of known direct dependencies, authority, and optional view-uses URL.
- Create `packages/admin/src/Actions/Pages/BuildPageDeletionImpactAction.php` and `packages/admin/src/Actions/Layouts/BuildLayoutDeletionImpactAction.php`: build only the bounded facts that the existing delete action can safely disclose.
- Modify `packages/admin/src/Data/Pages/PageRelationshipCountsData.php` and `packages/admin/src/Filament/Resources/PageUrls/Tables/PageUrlsTable.php`: add a pageable filter and matching Page URL management URLs to already-resolved positive count data.
- Modify `packages/admin/src/Support/Layouts/LayoutCardData.php`, `packages/admin/src/Filament/Components/Forms/Page/LayoutSelect.php`, and `packages/admin/src/Filament/Resources/Layouts/Tables/LayoutsTable.php`: use the existing variation-aware aggregate for links, filters, and impact summaries.
- Modify `packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php`: add availability-only filters while retaining the existing publication filter.
- Modify `packages/admin/src/Filament/Resources/Media/Tables/MediaTable.php`: add an authority-labelled tracked-use filter and deep link to its existing usage surface.
- Modify `packages/admin/resources/lang/en/table.php` and `packages/admin/resources/lang/en/generic.php`: translated filter, impact, and work-queue copy.
- Add/update focused Admin tests in `packages/admin/tests/Feature/Actions/Pages`, `packages/admin/tests/Feature/Actions/Layouts`, `packages/admin/tests/Feature/Filament/Resources/Page`, `packages/admin/tests/Feature/Filament/Resources/Layout`, `packages/admin/tests/Feature/Filament/Resources/Media`, and `packages/admin/tests/Feature/Filament/Components`.

Layout Builder changes live in `/Users/ben/Sites/packages/capell/capell-packages-4`.

- Create `packages/layout-builder/src/Data/Dashboard/LayoutHealthWorkQueueItemData.php` and `packages/layout-builder/src/Actions/BuildLayoutHealthWorkQueueAction.php`: package-owned scoped work-queue facts and URLs.
- Create `packages/layout-builder/src/Actions/BuildWidgetDeletionImpactAction.php`: widget placement/asset impact facts without decoding layout content per row.
- Modify `packages/layout-builder/src/Filament/Resources/Widgets/Tables/WidgetsTable.php`, `WidgetAssetsTable.php`, `WidgetSelectionTable.php`, `packages/layout-builder/src/Filament/Components/Forms/WidgetSelect.php`, and `Pages/EditWidget.php`: filters, state-bearing selection labels, and impact copy.
- Modify `packages/layout-builder/src/Data/Dashboard/LayoutHealthData.php`, `packages/layout-builder/src/Filament/Widgets/LayoutHealthFilamentWidget.php`, and `resources/views/filament/widgets/layout-health.blade.php`: render the bounded work queue and deep links.
- Modify `packages/layout-builder/resources/lang/en/table.php` and `packages/layout-builder/resources/lang/en/widgets.php`: translated state, filter, impact, and work-queue copy.
- Add/update focused tests in `packages/layout-builder/tests/Unit/Filament`, `packages/layout-builder/tests/Feature/Filament/Resources/Widget`, and `packages/layout-builder/tests/Unit`.

### Task 1: Establish authority-aware deletion-impact data and presentation

**Files:**

- Create: `packages/admin/src/Data/RecordDeletionImpactData.php`
- Create: `packages/admin/resources/views/components/record-deletion-impact.blade.php`
- Modify: `packages/admin/resources/lang/en/generic.php`
- Test: `packages/admin/tests/Feature/Filament/Components/RecordDeletionImpactPresentationTest.php`

- [x] **Step 1: Write failing rendering and data tests**

    Add tests that construct these three cases and render the component:

    ```php
    new RecordDeletionImpactData(
        knownReferenceCount: 0,
        authoritative: true,
        noReferencesLabel: __('capell-admin::generic.deletion_impact_unused'),
    );

    new RecordDeletionImpactData(
        knownReferenceCount: 3,
        authoritative: true,
        affectedLabel: trans_choice('capell-admin::generic.deletion_impact_pages', 3, ['count' => 3]),
        referencesUrl: '/admin/pages?filters[layout_id][value]=7',
    );

    new RecordDeletionImpactData(
        knownReferenceCount: 0,
        authoritative: false,
        noReferencesLabel: __('capell-admin::generic.deletion_impact_no_tracked_uses'),
    );
    ```

    Assert visible text in all cases, a link only for the known-reference case,
    and that neither zero case renders the word `Unused` unless `authoritative`
    is true.

- [x] **Step 2: Run the focused test to verify the missing contract**

    Run:

    ```bash
    ./vendor/bin/pest packages/admin/tests/Feature/Filament/Components/RecordDeletionImpactPresentationTest.php --configuration=phpunit.xml
    ```

    Expected: FAIL because the Data class and Blade component do not exist.

- [x] **Step 3: Implement the immutable contract and accessible component**

    Create `RecordDeletionImpactData` with this public constructor shape:

    ```php
    final class RecordDeletionImpactData extends Data
    {
        public function __construct(
            public readonly int $knownReferenceCount,
            public readonly bool $authoritative,
            public readonly string $noReferencesLabel,
            public readonly ?string $affectedLabel = null,
            public readonly ?string $referencesUrl = null,
            public readonly ?string $reviewLabel = null,
        ) {}
    }
    ```

    The Blade component must render `noReferencesLabel` when the count is zero;
    otherwise it renders `affectedLabel` and wraps it in an anchor only when
    `referencesUrl` is non-empty. `reviewLabel` is supplementary visible copy for
    incomplete/broken evidence. Do not compose HTML in an Action.

- [x] **Step 4: Add translations and verify the test passes**

    Add singular/plural impact labels and the two explicit zero labels to
    `generic.php`, then rerun Step 2. Expected: PASS.

- [x] **Step 5: Commit the shared contract**

    ```bash
    git add packages/admin/src/Data/RecordDeletionImpactData.php packages/admin/resources/views/components/record-deletion-impact.blade.php packages/admin/resources/lang/en/generic.php packages/admin/tests/Feature/Filament/Components/RecordDeletionImpactPresentationTest.php
    git commit -m "feat(admin): add record deletion impact summaries"
    ```

### Task 2: Add page and layout availability discovery, count links, and delete copy

**Files:**

- Create: `packages/admin/src/Actions/Pages/BuildPageDeletionImpactAction.php`
- Create: `packages/admin/src/Actions/Layouts/BuildLayoutDeletionImpactAction.php`
- Modify: `packages/admin/src/Data/Pages/PageRelationshipCountsData.php`
- Modify: `packages/admin/src/Filament/Resources/PageUrls/Tables/PageUrlsTable.php`
- Modify: `packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php`
- Modify: `packages/admin/src/Filament/Resources/Layouts/Tables/LayoutsTable.php`
- Modify: `packages/admin/src/Support/Layouts/LayoutCardData.php`
- Modify: `packages/admin/src/Filament/Components/Forms/Page/LayoutSelect.php`
- Test: `packages/admin/tests/Feature/Actions/Pages/BuildPageDeletionImpactActionTest.php`
- Test: `packages/admin/tests/Feature/Actions/Layouts/BuildLayoutDeletionImpactActionTest.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Page/Tables/PagesTableAvailabilityFilterTest.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Layout/Pages/ListLayoutsTest.php`
- Test: `packages/admin/tests/Feature/Filament/Components/LayoutSelectTest.php`

- [x] **Step 1: Write failing Action tests for bounded page and layout facts**

    Cover a page with zero/one URL and a layout used by pages in two registered
    page variations. Assert the Actions return these exact facts:

    ```php
    expect(BuildPageDeletionImpactAction::run($page))->toMatchObject([
        'knownReferenceCount' => 1,
        'authoritative' => true,
        'referencesUrl' => PageUrlResource::getUrl('index', [
            'filters[pageable][pageable_type]' => $page->getMorphClass(),
            'filters[pageable][pageable_id]' => $page->getKey(),
        ]),
    ]);

    expect(BuildLayoutDeletionImpactAction::run($layout))->toMatchObject([
        'knownReferenceCount' => 2,
        'authoritative' => true,
    ]);
    ```

    The layout test must prove both variation tables contribute to the count and
    that a site-scoped actor sees only permitted page references.

- [x] **Step 2: Add failing table/filter tests**

    In `PagesTableAvailabilityFilterTest`, reflect and invoke a new
    `applyAvailabilityFilterQuery(Builder $query, array $data)` for:

    ```php
    ['value' => 'no_active_url']
    ['value' => 'some_urls_disabled']
    ```

    Seed enabled and disabled `PageUrl` rows and assert the first query selects
    only pages without an enabled URL while the second selects pages that have at
    least one enabled URL and at least one disabled URL. In `ListLayoutsTest`,
    assert `disabled` and `unused` filters respect the existing
    `getUsesCountSelect()` site constraint and that a positive page count renders
    a page-list URL. In `LayoutSelectTest`, assert the same aggregate produces a
    deep link, not a fresh per-option query.

- [x] **Step 3: Run the focused failing tests**

    Run:

    ```bash
    ./vendor/bin/pest packages/admin/tests/Feature/Actions/Pages/BuildPageDeletionImpactActionTest.php packages/admin/tests/Feature/Actions/Layouts/BuildLayoutDeletionImpactActionTest.php packages/admin/tests/Feature/Filament/Resources/Page/Tables/PagesTableAvailabilityFilterTest.php packages/admin/tests/Feature/Filament/Resources/Layout/Pages/ListLayoutsTest.php packages/admin/tests/Feature/Filament/Components/LayoutSelectTest.php --configuration=phpunit.xml
    ```

    Expected: FAIL because the Actions, availability filter, and URL-bearing
    relationship data are not implemented.

- [x] **Step 4: Implement the page data flow**

    Add a `SelectFilter::make('availability')` to `PagesTable::getBaseTableFilters()`
    with translated options `no_active_url` and `some_urls_disabled`. Implement
    its query with relationships, not presentation state:

    ```php
    return match ($value) {
        'no_active_url' => $query->whereDoesntHave('pageUrls', fn (Builder $urlQuery): Builder => $urlQuery->where('status', true)),
        'some_urls_disabled' => $query
            ->whereHas('pageUrls', fn (Builder $urlQuery): Builder => $urlQuery->where('status', true))
            ->whereHas('pageUrls', fn (Builder $urlQuery): Builder => $urlQuery->where('status', false)),
        default => $query,
    };
    ```

    Add `Filter::make('pageable')` to `PageUrlsTable` with hidden
    `pageable_type` and `pageable_id` fields, and apply both columns when they
    are populated. Make `PageRelationshipCountsData::fromPage()` supply the
    matching Page URL index URL only when `page_urls_count > 0`.
    `BuildPageDeletionImpactAction` reuses that count and URL; it does not
    inspect page content or discover indirect uses.
    Set the record and bulk Page delete modal content to the shared impact
    component, preserving `validateDelete()` and `PageDeletedAction` hooks.

- [x] **Step 5: Implement variation-aware layout flow**

    Add `TernaryFilter::make('status')` for disabled layouts and a separate `Filter::make('unused')`
    that compares the same `LayoutsTable::getUsesCountSelect($query)` expression
    to zero. Reuse the existing selected aggregate in `LayoutCardData` and
    `LayoutSelect`; populate `RecordRelationshipCountData::$url` only from a
    stable filtered page-resource URL. Do not loop through page variations in a
    formatter closure.

    `BuildLayoutDeletionImpactAction` receives a Layout with `pages_count` when
    present and otherwise performs one actor-scoped variation aggregate. It marks
    the result authoritative only for that complete registered variation query.
    Attach the shared component to individual and bulk Layout delete actions
    without changing `validateDelete()` behaviour.

- [x] **Step 6: Run tests and static analysis**

    Rerun Step 3, then run:

    ```bash
    composer analyze
    ```

    Expected: focused tests PASS and static analysis reports 0 errors.

- [x] **Step 7: Commit page and layout work**

    ```bash
    git add packages/admin/src/Actions/Pages packages/admin/src/Actions/Layouts packages/admin/src/Data/Pages/PageRelationshipCountsData.php packages/admin/src/Filament/Resources/PageUrls/Tables/PageUrlsTable.php packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php packages/admin/src/Filament/Resources/Layouts/Tables/LayoutsTable.php packages/admin/src/Support/Layouts/LayoutCardData.php packages/admin/src/Filament/Components/Forms/Page/LayoutSelect.php packages/admin/resources/lang/en/table.php packages/admin/resources/lang/en/generic.php packages/admin/tests/Feature/Actions/Pages packages/admin/tests/Feature/Actions/Layouts packages/admin/tests/Feature/Filament/Resources/Page packages/admin/tests/Feature/Filament/Resources/Layout packages/admin/tests/Feature/Filament/Components/LayoutSelectTest.php
    git commit -m "feat(admin): make page and layout exceptions actionable"
    ```

### Task 3: Add media tracked-use discovery without inventing an exhaustive asset claim

**Files:**

- Modify: `packages/admin/src/Filament/Resources/Media/Tables/MediaTable.php`
- Modify: `packages/admin/resources/lang/en/table.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Media/Pages/ListMediaTest.php`

- [x] **Step 1: Write failing media filter and deep-link tests**

    Seed one media record with an `AssetAttachment` and one without. Assert a new
    `tracked_use` filter selects only the zero-attachment record and its indicator
    says `No tracked uses`. Assert the existing
    positive usage count continues to link to the existing media usage surface;
    zero remains text, never a cleanup action.

- [x] **Step 2: Run the focused test to confirm failure**

    ```bash
    ./vendor/bin/pest packages/admin/tests/Feature/Filament/Resources/Media/Pages/ListMediaTest.php --configuration=phpunit.xml
    ```

    Expected: FAIL because no tracked-use filter is registered.

- [x] **Step 3: Implement the bounded filter and presentation**

    Add a `SelectFilter::make('tracked_use')` with `used` and `unused` options.
    Use the model's existing attachment usage query/count source rather than a
    generic media `doesntHave()` relation, so the predicate exactly matches the
    established `usage_count` semantics. Keep the `usage_count` column's warning
    badge, use `No tracked uses` for zero, and add an owner-authorized usage URL
    only for positive counts. The attachment tracker is not represented as a
    complete cross-extension reference boundary.

- [x] **Step 4: Rerun test and commit**

    ```bash
    ./vendor/bin/pest packages/admin/tests/Feature/Filament/Resources/Media/Pages/ListMediaTest.php --configuration=phpunit.xml
    git add packages/admin/src/Filament/Resources/Media/Tables/MediaTable.php packages/admin/resources/lang/en/table.php packages/admin/tests/Feature/Filament/Resources/Media/Pages/ListMediaTest.php
    git commit -m "feat(admin): filter media by tracked usage"
    ```

    Expected: test PASS; the commit contains no media deletion or storage changes.

### Task 4: Make Layout Builder widget and widget-asset state discoverable in tables, headers, and selects

**Files:**

- Create: `packages/layout-builder/src/Actions/BuildWidgetDeletionImpactAction.php`
- Modify: `packages/layout-builder/src/Filament/Resources/Widgets/Tables/WidgetsTable.php`
- Modify: `packages/layout-builder/src/Filament/Resources/Widgets/Tables/WidgetAssetsTable.php`
- Modify: `packages/layout-builder/src/Filament/Resources/Widgets/Tables/WidgetSelectionTable.php`
- Modify: `packages/layout-builder/src/Filament/Components/Forms/WidgetSelect.php`
- Modify: `packages/layout-builder/src/Filament/Resources/Widgets/Pages/EditWidget.php`
- Modify: `packages/layout-builder/resources/lang/en/table.php`
- Test: `packages/layout-builder/tests/Unit/Filament/LayoutBuilderResourceTableCoverageTest.php`
- Test: `packages/layout-builder/tests/Feature/Filament/Resources/Widget/Pages/EditWidgetTest.php`
- Test: `packages/layout-builder/tests/Unit/WidgetDeletionImpactActionTest.php`

- [x] **Step 1: Write failing widget state tests**

    Extend table coverage to prove that widgets with `layouts_count = 0` match an
    `unused` filter, disabled widgets match the existing status filter, and the
    `WidgetSelect` label includes both disabled/unavailable and usage state.
    Seed a widget asset with a missing `asset` target and one with a valid
    layout-level target without pageable fields; assert their integrity filter
    separates `broken_reference` from `unscoped`.

    Add a deletion-impact Action test asserting a widget with layout placements
    receives a positive count and Layout index URL, while a zero count uses the
    authority-aware zero label. The test must use the existing indexed
    `layouts_count` query/source and must not construct JSON layout content in
    PHP.

- [x] **Step 2: Run the companion focused tests and confirm failure**

    ```bash
    ./vendor/bin/pest packages/layout-builder/tests/Unit/Filament/LayoutBuilderResourceTableCoverageTest.php packages/layout-builder/tests/Feature/Filament/Resources/Widget/Pages/EditWidgetTest.php packages/layout-builder/tests/Unit/WidgetDeletionImpactActionTest.php --configuration=phpunit.xml
    ```

    Expected: FAIL because the filters, select state metadata, header summary,
    and deletion-impact Action are absent.

- [x] **Step 3: Implement widget state and safe impact flow**

    Ensure `WidgetsTable` selects the existing `layouts_count` aggregate once;
    add a `Filter::make('usage')` that uses its query-safe source for `unused`.
    Update `WidgetSelect` to eager-load the same count plus status and pass
    `RecordStateData`/`RecordRelationshipCountData` into `HasCustomSelectOption`:

    ```php
    'states' => array_values(array_filter([
        $record->isDisabled() ? new RecordStateData(
            key: 'disabled',
            label: (string) __('capell-admin::generic.disabled'),
            description: (string) __('capell-layout-builder::table.widget_unavailable_tooltip'),
            color: 'danger',
            icon: Heroicon::OutlinedEyeSlash,
            priority: 10,
        ) : null,
        $layoutsCount === 0 ? new RecordStateData(
            key: 'unused',
            label: (string) __('capell-admin::table.unused'),
            description: (string) __('capell-layout-builder::table.widget_usage_unused_tooltip'),
            color: 'warning',
            icon: Heroicon::OutlinedExclamationTriangle,
            priority: 20,
        ) : null,
    ])),
    'relationships' => [new RecordRelationshipCountData(
        key: 'layouts',
        label: (string) __('capell-admin::table.total_layouts'),
        count: $layoutsCount,
        url: $layoutsCount > 0 ? $layoutsUrl : null,
    )],
    ```

    Replace `EditWidget::getSubheading()`'s hand-built disabled span with the
    shared Admin state summary. Add the custom Admin `DeleteAction` modal content
    from `BuildWidgetDeletionImpactAction` to widget delete actions only;
    preserve soft-delete and force-delete authorization.

    In `WidgetAssetsTable`, eager-load `widget`, `asset`, and `pageable`; add a
    dedicated state filter whose predicates match the existing `usage_status`
    logic. Missing targets are `Broken reference`; valid no-pageable assignment
    is `Unscoped`. Do not call either `Unused`.

- [x] **Step 4: Rerun focused tests and package-local static analysis**

    ```bash
    ./vendor/bin/pest packages/layout-builder/tests/Unit/Filament/LayoutBuilderResourceTableCoverageTest.php packages/layout-builder/tests/Feature/Filament/Resources/Widget/Pages/EditWidgetTest.php packages/layout-builder/tests/Unit/WidgetDeletionImpactActionTest.php --configuration=phpunit.xml
    ./vendor/bin/phpstan analyse packages/layout-builder/src/Actions/BuildWidgetDeletionImpactAction.php packages/layout-builder/src/Filament/Resources/Widgets/Tables/WidgetsTable.php packages/layout-builder/src/Filament/Resources/Widgets/Tables/WidgetAssetsTable.php packages/layout-builder/src/Filament/Components/Forms/WidgetSelect.php packages/layout-builder/src/Filament/Resources/Widgets/Pages/EditWidget.php --memory-limit=4G --configuration=phpstan.neon
    ```

    Expected: focused tests PASS and targeted PHPStan reports 0 errors. If the
    unrelated `NavigationSelect` nullable-string finding still blocks full
    companion analysis, record it separately and do not alter that unrelated
    file.

- [x] **Step 5: Commit companion resource work**

    ```bash
    git add packages/layout-builder/src/Actions/BuildWidgetDeletionImpactAction.php packages/layout-builder/src/Filament/Resources/Widgets packages/layout-builder/src/Filament/Components/Forms/WidgetSelect.php packages/layout-builder/resources/lang/en/table.php packages/layout-builder/tests/Unit/Filament/LayoutBuilderResourceTableCoverageTest.php packages/layout-builder/tests/Feature/Filament/Resources/Widget/Pages/EditWidgetTest.php packages/layout-builder/tests/Unit/WidgetDeletionImpactActionTest.php
    git commit -m "feat(layout-builder): make widget states actionable"
    ```

### Task 5: Turn the existing Layout Health widget into a scoped work queue

**Files:**

- Create: `packages/layout-builder/src/Data/Dashboard/LayoutHealthWorkQueueItemData.php`
- Create: `packages/layout-builder/src/Actions/BuildLayoutHealthWorkQueueAction.php`
- Modify: `packages/layout-builder/src/Data/Dashboard/LayoutHealthData.php`
- Modify: `packages/layout-builder/src/Filament/Widgets/LayoutHealthFilamentWidget.php`
- Modify: `packages/layout-builder/resources/views/filament/widgets/layout-health.blade.php`
- Modify: `packages/layout-builder/resources/lang/en/widgets.php`
- Test: `packages/layout-builder/tests/Unit/BuildLayoutHealthWorkQueueActionTest.php`
- Test: `packages/layout-builder/tests/Unit/LayoutBuilderThirdRoundResidualCoverageTest.php`

- [x] **Step 1: Write failing work-queue Action tests**

    Build fixtures for unused widgets, disabled widgets, broken widget assets,
    unscoped widget assets, disabled layouts, and a page with no active URL.
    Assert the Action returns only items accessible to the actor, each item has a
    translated label/count, and only records with a stable resource query receive
    a URL. Assert a zero count for a non-authoritative boundary has label `No
tracked uses`, not `Unused`.

- [x] **Step 2: Run the focused test to confirm failure**

    ```bash
    ./vendor/bin/pest packages/layout-builder/tests/Unit/BuildLayoutHealthWorkQueueActionTest.php packages/layout-builder/tests/Unit/LayoutBuilderThirdRoundResidualCoverageTest.php --configuration=phpunit.xml
    ```

    Expected: FAIL because the Action and work-queue data are missing.

- [x] **Step 3: Implement one bounded work-queue Action**

    Create this data shape:

    ```php
    final class LayoutHealthWorkQueueItemData extends Data
    {
        public function __construct(
            public readonly string $key,
            public readonly string $label,
            public readonly int $count,
            public readonly string $color,
            public readonly ?string $url,
            public readonly bool $authoritative,
        ) {}
    }
    ```

    `BuildLayoutHealthWorkQueueAction` owns all queries and returns a short,
    deterministic list. It uses `AdminSurfaceLookup::resourceIfRegistered()`
    before attempting Admin URLs, applies the current actor's scope to layouts
    and pages, and returns no page/layout item when the matching Admin resource is
    unavailable. It never loops over layout JSON to count uses.

    Add `Collection<int, LayoutHealthWorkQueueItemData> $workQueue` to
    `LayoutHealthData`, replace the widget's ad-hoc unused-widget query with the
    Action result, and render a compact linked list under a translated
    `Needs attention` heading. Preserve the existing group and least-used-widget
    information in the same rendering path.

- [x] **Step 4: Verify presentation and query safety**

    Rerun Step 2 and add a view assertion for the heading, count, state label,
    and URL. Confirm the Blade view accesses only `$data->workQueue` properties;
    it must not call model relations or Actions.

- [x] **Step 5: Commit the health queue**

    ```bash
    git add packages/layout-builder/src/Data/Dashboard packages/layout-builder/src/Actions/BuildLayoutHealthWorkQueueAction.php packages/layout-builder/src/Filament/Widgets/LayoutHealthFilamentWidget.php packages/layout-builder/resources/views/filament/widgets/layout-health.blade.php packages/layout-builder/resources/lang/en packages/layout-builder/tests/Unit/BuildLayoutHealthWorkQueueActionTest.php packages/layout-builder/tests/Unit/LayoutBuilderThirdRoundResidualCoverageTest.php
    git commit -m "feat(layout-builder): add record health work queue"
    ```

### Task 6: Run regression verification, update the design status, and record the cross-repository handoff

**Files:**

- Modify: `docs/admin/record-state-ux-follow-up-design.md`
- Modify: `docs/admin/record-state-ux-follow-up-plan.md`
- Modify: `/Users/ben/Sites/internal-ledger/projects/capell/TODO.md` in a safe ledger branch/worktree only

- [x] **Step 1: Run focused cross-repository regression suites**

    In Core/Admin:

    ```bash
    ./vendor/bin/pest packages/admin/tests/Feature/Actions/Pages packages/admin/tests/Feature/Actions/Layouts packages/admin/tests/Feature/Filament/Components/RecordDeletionImpactPresentationTest.php packages/admin/tests/Feature/Filament/Resources/Page packages/admin/tests/Feature/Filament/Resources/Layout packages/admin/tests/Feature/Filament/Resources/Media --configuration=phpunit.xml
    composer analyze
    php scripts/check-docs-links.php
    ```

    In Layout Builder:

    ```bash
    ./vendor/bin/pest packages/layout-builder/tests/Unit/Filament/LayoutBuilderResourceTableCoverageTest.php packages/layout-builder/tests/Feature/Filament/Resources/Widget/Pages/EditWidgetTest.php packages/layout-builder/tests/Unit/WidgetDeletionImpactActionTest.php packages/layout-builder/tests/Unit/BuildLayoutHealthWorkQueueActionTest.php --configuration=phpunit.xml
    ./vendor/bin/phpstan analyse packages/layout-builder/src/Actions/BuildWidgetDeletionImpactAction.php packages/layout-builder/src/Actions/BuildLayoutHealthWorkQueueAction.php packages/layout-builder/src/Filament/Resources/Widgets packages/layout-builder/src/Filament/Widgets/LayoutHealthFilamentWidget.php --memory-limit=4G --configuration=phpstan.neon
    ```

    Expected: focused suites and targeted static analysis PASS. Report the known
    unrelated full companion PHPStan failure only if it still occurs.

- [x] **Step 2: Perform an Admin browser/user test if the local application is available**

    Verify a disabled/scheduled page, unused layout, unused/no-tracked-use media,
    unused widget, and broken widget asset in their actual table and selected
    option. Verify each deep link preserves its filter and that delete modals show
    consequence copy without changing delete eligibility. Capture no customer
    data and do not perform a deletion.

- [x] **Step 3: Request and resolve the Sol expert review**

    Request a read-only Sol review of the exact Core/Admin and Layout Builder
    diffs after the focused suites pass. Give the reviewer the approved design,
    the authority rule for `Unused`, the no-query-in-rendering rule, and the
    known unrelated companion PHPStan finding. Resolve every P1/P2 finding and
    any low-risk P3 correctness/accessibility issue, then rerun the focused tests
    that cover the changed area. Record accepted residuals only when they are
    explicitly outside this slice.

- [x] **Step 4: Reconcile documentation and ledger**

    Mark completed plan checkboxes, update the design status to implemented, and
    add exact commits/test output/review result to CAP-0080. Preserve the primary
    ledger checkout's unrelated changes by using a dedicated ledger branch or
    worktree; do not push, open a PR, merge, release, deploy, seed, or migrate.

- [x] **Step 5: Commit closeout documentation separately in each repository**

    ```bash
    git add docs/admin/record-state-ux-follow-up-design.md docs/admin/record-state-ux-follow-up-plan.md
    git commit -m "docs(admin): record state UX follow-up verification"
    ```

    Commit the ledger only on its dedicated branch with a CAP-0080-specific
    message. Keep Core/Admin and Layout Builder commits focused; do not combine
    companion changes with the existing unrelated release-catalogue history.
