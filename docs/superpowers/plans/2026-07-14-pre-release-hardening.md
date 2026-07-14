# Pre-release hardening — 2026-07-14

Four workstreams from the 2026-07-14 architecture review. Executed sequentially on
`feature/curated-capell-all-launch`; one focused commit per phase.

## Phase A — Core-owned publishing visibility-state module

**Problem.** The far-future draft sentinel (`visible_from = now()+100y`, boundary +50y) is
classified differently per caller: publish panel + page table say *draft*,
`ResolvePagePublishStateAction` says *scheduled* (`scheduledPublishAt` = year 2126, feeds
`PageProjector` + `PublishStatusPanel`), Core `HasPublishDates`/`PublishStatusEnum` say
*pending*, and the sentinel math is duplicated in `PagesTable::applyPublishStatusFilterQuery`,
`DefaultPageTableStatusResolver`, `CreatePage:249`, `CreatePageAction:156`. Dashboard
`BuildDefaultSiteStatsAction` counts sentinel drafts in `pendingCount`/`workQueueCount`.

**Design.**
- [x] New `Capell\Core\Support\Publishing\PublishSentinel`: `DRAFT_BOUNDARY_YEARS = 50`,
  write offset +50 (sentinel = +100y), `draftValue(?CarbonImmutable $now = null)`,
  `draftBoundary(?CarbonImmutable $now = null)`, `isDraftValue(?DateTimeInterface, ?CarbonImmutable $now = null)`.
  Carry the DATETIME-not-TIMESTAMP warning comment.
- [x] New `Capell\Core\Enums\PublishVisibilityStateEnum` (string-backed, `HasLabel`):
  `draft|scheduled|published|expired|deleted`. Static
  `fromDates(?DateTimeInterface $visibleFrom, ?DateTimeInterface $visibleUntil, bool $trashed, ?CarbonImmutable $now = null)`.
  Precedence (matches both existing Admin classifiers): deleted → expired → draft (sentinel)
  → scheduled → published. Exactly-at-boundary = scheduled (`greaterThan` semantics).
- [x] `HasPublishDates`: add `publishVisibilityState(?CarbonImmutable $now = null)`,
  `isDraftSentinel()`, `scopeDraftSentinel`, `scopeScheduled` (future AND ≤ boundary).
  `isPending()`/`scopePending` UNCHANGED (umbrella incl. drafts — documented compat).
- [x] `PublishStatusEnum::fromModel` delegates to the state module via a
  `fromVisibilityState()` mapping (draft+scheduled → pending) — behaviour identical.
- [x] Admin `PagePublishSentinel` delegates to Core `PublishSentinel` (API kept; tests kept).
  `DefaultPageTableStatusResolver::DRAFT_SENTINEL_YEARS` now references the Core constant.
- [x] `ResolvePagePublishStateAction`: `isDraft` = workspace draft OR sentinel;
  `scheduledPublishAt` only for genuine schedules. (The behaviour fix.)
- [x] `ResolvePublishPanelViewAction`, `DefaultPageTableStatusResolver` derive from
  `publishVisibilityState()`; Admin keeps DTOs/labels/colors/icons/workspace context.
- [x] `PagesTable::applyPublishStatusFilterQuery` uses Core scopes; no inline date math.
- [x] `BuildDefaultSiteStatsAction`: `pendingCount` uses `scheduled()` so sentinel drafts no
  longer count as scheduled work (regression-tested behaviour change, called out in review).
- [x] Regression tests FIRST (red → green): Core enum derivation (frozen time, all states +
  boundary edge), trait state/scopes/`isPending` compat + `publishedDate()` exclusion,
  `ResolvePagePublishStateAction` sentinel-not-scheduled, resolver/panel/filter parity.

Constraints: no migration, no new dependency, no one-adapter contract, public rendering
(`publishedDate()`) unchanged.

## Phase B — Lazy fragment interaction targets

**Problem.** `docs/frontend/widget-targets.md` promises fragment targets; Core
`ResolveInteractionTriggersAction` parses them; Frontend
`BuildInteractionRenderDataAction:70-76` warns "not implemented" and strips the trigger;
Admin `InteractionSettingsSchema::targetOptions()` never offers Fragment.
`DeferredFragmentReferenceBuilder` has zero implementations/bindings (free to adjust).

- [ ] `BuildInteractionRenderDataAction`: when `DeferredFragmentReferenceBuilder` is bound,
  produce the trigger URL through it (mirror the `WidgetInteractionLocatorResolver`
  `app()->bound()` guard); when unbound, drop trigger with a debug-level log (no warning spam).
- [ ] `InteractionSettingsSchema`: offer Fragment target (+ `fragment_reference` field)
  only when the builder is bound.
- [ ] Tests: bound fake builder → trigger rendered with builder URL (opaque, no model IDs);
  unbound → trigger safely dropped; Core fragment parsing covered in
  `InteractionTriggersTest`.
- [ ] Docs: state plainly that fragment/widget targets activate when a companion package
  binds the contract; align the `/_fragments` wording with reality.

## Phase C — Installer patch ownership

**Problem.** `InstallCommand.php:36-37` `use`-imports `Capell\Installer\Support\InstallGuide\Patches\{AdminPanelThemePatch,UserModelPatch}` (class_exists-guarded at 450/457 but a hard namespace dependency), bypassing installer's own `PatchRegistry`. `{EnvFileEditor,ConfigArrayEditor,PhpFileEditor}` are triplicated: Core `Support/Patching` (canonical), installer `Support/Patching` (diverged: non-null `$className`, missing `findNamespace/originalContent/print`, widened `insertKey(Node)`), and Admin `Support/AdminPanelIntegration/PhpFileEditor`. `CorePackageTest` arch test doesn't forbid `Capell\Installer`.

- [ ] Reconcile editors into Core `Support/Patching` (superset: nullable `$className` +
  `class_basename` match, alias fallback, `findNamespace/originalContent/print`,
  `insertKey(Node)`); delete installer + admin copies, repoint imports.
- [ ] Core-owned patch seam (modeled on `ThemeInstallDefaultsRegistry`): installer's
  provider registers `UserModelPatch`/`AdminPanelThemePatch`; `InstallCommand::prepareApplication()`
  consumes the registry — drop the `Capell\Installer` imports.
- [ ] Arch test: add `Capell\Installer` to `CorePackageTest` forbidden namespaces.
- [ ] Update `docs/development/package-boundaries.md`; run installer InstallGuide +
  core InstallCommand suites for parity.

## Phase D — Demo / Install Health report

**Problem.** `BuildDemoInstallHealthReportAction` extends `BuildEmptyReportAction` —
always-empty snapshot at `/reports/demo-install-health`.

- [ ] Implement real snapshot following `BuildPackageReadinessReportAction` pattern:
  reuse `BuildDoctorReportAction` checks + demo-specific metrics (sites/pages/admin user/
  storage link/default theme+layout/settings rows/event-sourcing tables).
- [ ] Metrics as `ReportMetricData`, failures as `ReportFindingData` with severity;
  lang keys in `packages/admin/resources/lang/en/reports.php`.
- [ ] Feature test proving populated snapshot after install-shaped state (red first).

## Verification (per phase)

Narrow Pest first (changed packages), then affected suites; `composer preflight` batched at
the end before the final commits per repo cadence.
