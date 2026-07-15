# Publishing State Machine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Route every publication mutation and every publication-state consumer through one typed, deterministic Core state machine.

**Architecture:** Core owns transition types, date normalization, authorization dispatch, results, enum precedence, and mutually exclusive scopes. Admin Actions, bulk previews, filters, panels, dashboard counts, readiness reports, and workflow DTOs become adapters over Core. Dry-run evaluation and execution share the same evaluator.

**Tech Stack:** PHP 8.4+, Laravel Eloquent/Gate, CarbonImmutable, Spatie Laravel Data, Filament 4, Pest.

---

### Task 1: Specify publication transitions and typed outcomes

**Files:**
- Create: `packages/core/src/Enums/Publishing/PublicationTransition.php`
- Create: `packages/core/src/Enums/Publishing/PublicationTransitionOutcome.php`
- Create: `packages/core/src/Data/Publishing/PublicationTransitionRequestData.php`
- Create: `packages/core/src/Data/Publishing/PublicationTransitionResultData.php`
- Create: `packages/core/src/Exceptions/InvalidPublicationTransitionRequest.php`
- Test: `packages/core/tests/Unit/Publishing/PublicationTransitionDataTest.php`

- [ ] **Step 1: Write failing DTO tests** covering `publish-now`, `revert-to-draft`, `schedule-publish`, `schedule-unpublish`, and `unpublish`, including required/forbidden timestamps and explicit actor context.

- [ ] **Step 2: Implement the enums** with values exactly:

  ```php
  enum PublicationTransition: string
  {
      case PublishNow = 'publish-now';
      case RevertToDraft = 'revert-to-draft';
      case SchedulePublish = 'schedule-publish';
      case ScheduleUnpublish = 'schedule-unpublish';
      case Unpublish = 'unpublish';
  }

  enum PublicationTransitionOutcome: string
  {
      case Changed = 'changed';
      case AlreadyCorrect = 'already-correct';
      case Unauthorized = 'unauthorized';
      case InvalidTransition = 'invalid-transition';
      case Failed = 'failed';
  }
  ```

- [ ] **Step 3: Implement request/result types** so the request carries a publishable model, transition, optional requested time, authenticated actor, and frozen `now`; the result carries outcome, before/after state, normalized dates, and a stable reason key.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/core/tests/Unit/Publishing/PublicationTransitionDataTest.php`

  ```bash
  git add packages/core/src/Enums/Publishing packages/core/src/Data/Publishing packages/core/src/Exceptions packages/core/tests/Unit/Publishing
  git commit -m "feat(core): define publication transition boundary"
  ```

### Task 2: Implement one transition evaluator and executor

**Files:**
- Create: `packages/core/src/Actions/Publishing/EvaluatePublicationTransitionAction.php`
- Create: `packages/core/src/Actions/Publishing/TransitionPublicationAction.php`
- Create: `packages/core/src/Contracts/Publishing/AuthorizesPublicationTransition.php`
- Create: `packages/core/src/Support/Publishing/GatePublicationTransitionAuthorizer.php`
- Modify: `packages/core/src/Providers/CapellServiceProvider.php`
- Test: `packages/core/tests/Feature/Publishing/TransitionPublicationActionTest.php`

- [ ] **Step 1: Write the full red transition matrix** with frozen time. Include scheduled and expired records published now, effective expiry clearing, draft sentinel plus expiry, future scheduling, past scheduling rejection, expiry-before-publish rejection, no-op detection, unauthorized actor, and persistence failure.

- [ ] **Step 2: Implement pure evaluation** without saving. Normalize exactly:

  - Publish now: `visible_from = now`; clear `visible_until` only when it is effective at `now`.
  - Revert to draft: `visible_from = PublishSentinel::draftValue(now)` and `visible_until = null`.
  - Schedule publish: require requested time strictly after `now`.
  - Schedule unpublish: require requested time after `now` and after any effective scheduled publish.
  - Unpublish: set `visible_until = now`, preserving the publication start.

- [ ] **Step 3: Implement authorization and persistence** in `TransitionPublicationAction`. Use the authorizer contract before evaluation; wrap only the write in a transaction; convert expected validation/authorization into typed outcomes and unexpected exceptions into `failed` with a safe reason key.

- [ ] **Step 4: Run the matrix twice** once on SQLite and once against the configured integration database if available.

  Run: `vendor/bin/pest packages/core/tests/Feature/Publishing/TransitionPublicationActionTest.php`

  Expected: PASS; no fixture can produce more than one changed write.

- [ ] **Step 5: Commit**

  ```bash
  git add packages/core/src/Actions/Publishing packages/core/src/Contracts/Publishing packages/core/src/Support/Publishing packages/core/src/Providers packages/core/tests/Feature/Publishing
  git commit -m "feat(core): enforce publication transitions centrally"
  ```

### Task 3: Make publication SQL scopes a true partition

**Files:**
- Modify: `packages/core/src/Enums/PublishVisibilityStateEnum.php`
- Modify: `packages/core/src/Models/Concerns/HasPublishDates.php`
- Test: `packages/core/tests/Integration/Models/PagePublishVisibilityTest.php`
- Create: `packages/core/tests/Integration/Models/PublicationStatePartitionTest.php`

- [ ] **Step 1: Add a combinatorial fixture table** for null/past/now/future/sentinel `visible_from`, null/past/now/future `visible_until`, and deleted/not-deleted records. Freeze time to second precision.

- [ ] **Step 2: Assert enum precedence** is exactly deleted → expired → draft → scheduled → published, including contradictory imported dates and equality boundaries.

- [ ] **Step 3: Replace scopes** so each applies all exclusions needed for mutual exclusivity. `deleted()` uses only trashed records; every other scope excludes trashed; draft/scheduled/published exclude effective expiry; scheduled excludes draft sentinels; published excludes future starts.

- [ ] **Step 4: Add the partition invariant**

  ```php
  foreach ($pages as $page) {
      $matches = collect(PublishVisibilityStateEnum::cases())
          ->filter(fn (PublishVisibilityStateEnum $state): bool => queryFor($state)->whereKey($page)->exists());

      expect($matches)->toHaveCount(1)
          ->and($matches->first())->toBe($page->publishVisibilityState($now));
  }
  ```

- [ ] **Step 5: Run and commit**

  Run: `vendor/bin/pest packages/core/tests/Unit/Enums/PublishVisibilityStateEnumTest.php packages/core/tests/Integration/Models/PagePublishVisibilityTest.php packages/core/tests/Integration/Models/PublicationStatePartitionTest.php`

  ```bash
  git add packages/core/src/Enums/PublishVisibilityStateEnum.php packages/core/src/Models/Concerns/HasPublishDates.php packages/core/tests
  git commit -m "fix(core): partition publication state scopes"
  ```

### Task 4: Convert single-record Admin Actions into adapters

**Files:**
- Modify: `packages/admin/src/Actions/Publishing/PublishRecordAction.php`
- Modify: `packages/admin/src/Actions/Publishing/RevertRecordToDraftAction.php`
- Modify: `packages/admin/src/Actions/Publishing/ScheduleRecordPublishAction.php`
- Modify: `packages/admin/src/Actions/Publishing/ScheduleRecordUnpublishAction.php`
- Modify: `packages/admin/src/Actions/Publishing/UnpublishRecordAction.php`
- Delete: `packages/admin/src/Actions/Publishing/Concerns/NormalisesPublishDates.php`
- Modify: `packages/admin/tests/Feature/Actions/Pages/SinglePagePublishActionsTest.php`

- [ ] **Step 1: Change tests first** to assert each Admin Action constructs the correct typed request and returns the Core result without directly assigning `visible_from` or `visible_until`.

- [ ] **Step 2: Replace direct writes** with one call to `TransitionPublicationAction::run()`. Keep UI notification translation in Admin; map stable outcome/reason keys to translated messages.

- [ ] **Step 3: Delete duplicated date normalization** and prove with:

  Run: `rg -n "visible_(from|until)\s*=" packages/admin/src/Actions/Publishing`

  Expected: no direct assignments in the adapter directory.

- [ ] **Step 4: Run and commit**

  Run: `vendor/bin/pest packages/admin/tests/Feature/Actions/Pages/SinglePagePublishActionsTest.php packages/admin/tests/Feature/Actions/Publishing`

  ```bash
  git add packages/admin
  git commit -m "refactor(admin): delegate publishing mutations to core"
  ```

### Task 5: Add shared bulk evaluation, preview, and accurate counts

**Files:**
- Create: `packages/admin/src/Data/Publishing/BulkPublicationPreviewData.php`
- Create: `packages/admin/src/Actions/Publishing/PreviewBulkPublicationTransitionAction.php`
- Create: `packages/admin/src/Actions/Publishing/RunBulkPublicationTransitionAction.php`
- Modify: `packages/admin/src/Actions/Pages/BulkPublishPagesAction.php`
- Modify: `packages/admin/src/Filament/Resources/Pages/Actions/BulkPublishNowBulkAction.php`
- Modify: `packages/admin/src/Filament/Resources/Pages/Actions/BulkPublishPagesBulkAction.php`
- Create: `packages/admin/resources/views/filament/actions/bulk-publication-preview.blade.php`
- Test: `packages/admin/tests/Feature/Actions/Publishing/BulkPublicationTransitionTest.php`
- Test: `packages/admin/tests/Feature/Filament/Resources/Page/BulkPublicationPreviewTest.php`

- [ ] **Step 1: Write failing mixed-selection tests** with changed, already-correct, unauthorized, invalid-transition, and forced-failure records. Assert preview and execution totals use the same stable outcome keys.

- [ ] **Step 2: Implement preview** by calling `EvaluatePublicationTransitionAction` for each authorized record without persistence. The DTO contains per-record results and aggregate counts for every outcome.

- [ ] **Step 3: Implement execution** by calling `TransitionPublicationAction` per record and counting returned outcomes; do not report selected-record count as changed count.

- [ ] **Step 4: Add the confirmation preview** with translated headings and explicit lists for will change, unchanged, and blocked. Recompute at execution time so stale confirmation data cannot bypass policy.

- [ ] **Step 5: Run and commit**

  Run: `vendor/bin/pest packages/admin/tests/Feature/Actions/Publishing/BulkPublicationTransitionTest.php packages/admin/tests/Feature/Filament/Resources/Page/BulkPublicationPreviewTest.php`

  ```bash
  git add packages/admin/src/Actions packages/admin/src/Data packages/admin/src/Filament packages/admin/resources packages/admin/tests
  git commit -m "feat(admin): preview and count bulk publication outcomes"
  ```

### Task 6: Align every state consumer

**Files:**
- Modify: `packages/admin/src/Filament/Resources/Pages/Tables/PagesTable.php`
- Modify: `packages/admin/src/Actions/Pages/ResolvePublishPanelViewAction.php`
- Modify: `packages/admin/src/Actions/Pages/ResolvePagePublishStateAction.php`
- Modify: `packages/admin/src/Support/Pages/DefaultPageTableStatusResolver.php`
- Modify: `packages/admin/src/Actions/Dashboard/BuildDefaultSiteStatsAction.php`
- Modify: `packages/admin/src/Actions/Reports/BuildPublishingReadinessReportAction.php`
- Modify: `packages/admin/src/Actions/Dashboard/BuildPublishingWorkflowEntryAction.php`
- Create: `packages/admin/tests/Feature/Publishing/PublicationStateConsumerParityTest.php`

- [ ] **Step 1: Build one parity data provider** from the Core combinatorial matrix and assert enum, SQL filter, table resolver, panel, dashboard bucket, readiness finding, and workflow DTO agree at the same frozen timestamp.

- [ ] **Step 2: Replace remaining inline date decisions** with `publishVisibilityState($now)` or the matching named scope. Preserve Admin-only labels, icons, and workspace context.

- [ ] **Step 3: Run parity and regression suites**

  Run: `vendor/bin/pest packages/admin/tests/Feature/Publishing/PublicationStateConsumerParityTest.php packages/admin/tests/Feature/Filament/Resources/Page/Tables/PagesTablePublishStatusFilterTest.php packages/admin/tests/Feature/Reports/PublishingReadinessReportTest.php packages/admin/tests/Feature/Dashboard`

  Expected: PASS for all date combinations and equality boundaries.

- [ ] **Step 4: Commit**

  ```bash
  git add packages/admin
  git commit -m "fix(admin): align publication state consumers"
  ```

## Exit gate

- Publish Now makes scheduled and expired records genuinely published.
- Expired → draft resolves only to Draft.
- Every persisted record matches exactly one SQL scope and the enum agrees.
- UI, dashboard, readiness, workflow, and table filters agree at a shared timestamp.
- Bulk previews and notifications report actual typed outcomes.
- No Admin publication Action writes date columns independently.
