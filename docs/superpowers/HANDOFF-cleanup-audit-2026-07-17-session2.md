# Handoff — Cleanup Audit: Findings #4 and #1 complete, PR blocked on a stale base

**Written:** 2026-07-17
**Worktree:** `/Users/ben/Sites/packages/capell/capell-4-task7`
**Branch:** `cleanup/empty-reports` @ `30dbcfd50` (stacked on `task7/cancel-schedule`)
**Base:** `origin/cleanup/audit-roadmap` @ `d683a3223`
**Working tree:** clean. Nothing uncommitted.

---

## TL;DR for whoever picks this up

Two roadmap findings are **done, reviewed, and verified**. Seven commits, unpushed.
The only thing standing between here and a PR is that **the base branch is stale** —
the fix for the preflight failure already exists on `main` but is not on
`cleanup/audit-roadmap`. See "The one blocker" below; it is a merge, not a code fix.

**Do not** fix the install-handoff PHPStan errors yourself. Someone else already did.

---

## What landed

Seven commits on top of base `d683a3223`:

```
30dbcfd50  docs: mark cleanup findings #1 and #4 complete
ffcec6fb2  refactor(admin): remove eight reports that compute nothing        ← finding #1
50e3fc093  docs(admin): record why bulk schedule has no batch transaction
eb858a69f  docs: mark publishing plan Task 7 complete
03b8bbc61  test(core): extend transition contract with cancel-schedule
7f43a85de  test(admin): cover bulk revert adapter and pin schedule skip semantics
7c83c2127  refactor(admin): route bulk schedule actions through core state machine  ← finding #4
```

### Finding #4 — publishing bulk-action residual (Task 7 of the publishing plan)

Added `CancelSchedule` as a first-class Core transition; converted all three remaining
bulk actions (`BulkRevertPagesToDraftAction`, `BulkCancelScheduleAction`,
`BulkSchedulePagesBulkAction`) into adapters over `RunBulkPublicationTransitionAction`.

**This closed a real bug, not just a layering seam.** `BulkCancelScheduleAction` set
`visible_from = null` when cancelling. Per `PublishSentinel`'s own docblock, null means
*published, live now* — so **cancelling a scheduled publish pushed the page live**, the
exact inverse of intent. Because the draft sentinel is itself future-dated (now+100yr),
cancel-schedule on an *already-draft* page also nulled it and published it. An existing
test asserted `toBeNull()`, pinning the defect as correct behaviour; it was corrected.
The modal copy already promised the right behaviour — the code never implemented it.

### Finding #1 — eight empty reports

Deleted 8 report actions + their Filament pages + lang keys + registrations.
13 `ReportDefinitionData` registrations → 5. Zero dangling references in `packages/`.

---

## Verified facts (evidence, not recollection)

- **Full suite:** 5148 tests, 5146 passed. **2 failures, both pre-existing and neither ours**
  (`ServiceProvidersLoadedTest`, `LaravelActionsTraitContractTest`). Subjects verified
  byte-identical to base. Same 2 before the work started; same 2 after.
- **Task 7 scope:** 57 passed / 223 assertions (baseline 33/131).
- **Reports scope:** 35 passed / 184 assertions (baseline 43/214). The −8 is exactly the
  removed parameterized test `it('returns empty-state snapshots from core report actions')`
  and its 8 datasets — a test whose purpose was asserting the features did nothing.
  Not lost coverage.
- **PHPStan on all 19 surviving files touched:** `passed, 0 errors`. Rector + Pint pass.
- `rg -n "visible_(from|until)\s*="` across the three bulk actions → **zero** assignments.
- `rg` for all 8 report keys + `BuildEmptyReportAction` across `packages/` → **zero** hits.

---

## The one blocker — and it is NOT a code fix

`composer preflight` fails on **4 PHPStan errors** in
`packages/core/src/Actions/Install/BuildInstallHandoffAction.php` and
`BuildInstallRunResultAction.php` (`list<string>` vs `array<int,string>`).

**Zero `Install/*` files appear in any of the 7 commits.** These were already red at
base `d683a3223`.

**The fix already exists.** Commit `aaf9e67b0 "fix: build install handoff lists as lists"`
on **`main`** touches exactly those two files. Verified:

```
git merge-base --is-ancestor aaf9e67b0 origin/cleanup/audit-roadmap  →  NO
```

So: our base branch predates the fix. The other session resolved their own errors.

**Recommended path:** merge `main` (or an updated `cleanup/audit-roadmap`) into the branch.
Preflight is Rector + Pint + PHPStan and does **not** run tests, so this should turn
preflight green and satisfy the PR gate.

**Caveat — merging `main` will NOT fix the 2 test failures.** Verified on `main`:
`BuildInstallHandoffAction`, `WriteInstallHandoffAction`, and `BuildInstallRunResultAction`
all still have `AsFake=0`, so `LaravelActionsTraitContractTest` stays red there too.
That is a separate, pre-existing issue owned by the install work.

**Do NOT** "fix" the 4 errors on this branch — you would duplicate/conflict with
`aaf9e67b0`. Do not baseline or `@phpstan-ignore` them either.

---

## Open decisions — these are Ben's, not the agent's

Ben asked for "a PR after it has passed pre-flight". Preflight does not pass, for the
reason above. The question below was put to him but **not answered** — the session ended
first. Do not guess:

1. **Preflight gate:** open the PR disclosing the pre-existing red? Or merge `main` first
   to go green (recommended — the fix is already upstream)? Or wait?
2. **PR base:** `cleanup/audit-roadmap` (recommended — where both branches came from) or
   `main`?
3. **PR shape:** one PR with all 7 commits from `cleanup/empty-reports`, or two stacked
   PRs (one per finding)?
4. **Semver:** `BuildEmptyReportAction` was **public + abstract but undocumented**.
   Deleting it is technically breaking for any third party who extended it, on a 1.0.0
   line. Judged acceptable; Ben should confirm.

Nothing has been pushed. Nothing is merged.

---

## Environment gotchas — read before running anything

1. **NEVER symlink `vendor/` into a worktree.** Composer's `$baseDir` derives from
   `__DIR__` inside `vendor/composer/`, and PHP resolves symlinks — so a symlinked vendor
   makes every `Capell\*` class load from the **primary checkout**. Tests then pass while
   exercising the wrong code. This worktree has its own real `composer install`.
   Detect with:
   ```bash
   php -r 'require "vendor/autoload.php";
     $r = new ReflectionClass("Capell\Core\Enums\Publishing\PublicationTransition");
     echo $r->getFileName(), "\n";'
   ```
   The path must be inside the worktree.

2. **`BulkSchedulePagesBulkActionTest` OOMs at the default 128M `memory_limit`.**
   Needs `-d memory_limit=2048M`. Worth checking CI gives that path headroom.

3. **The primary checkout `/Users/ben/Sites/packages/capell/capell-4` was never touched**
   by this session — no checkout, stash, reset, or clean. It has since moved to
   `aaf9e67b0 [main]` under another session, with 2 uncommitted files
   (`.gitignore`, `docs/superpowers/plans/2026-07-14-publishing-state-machine.md`).
   **That second file is a potential conflict** — this branch has its own committed
   version of that plan with Task 7 ticked.

---

## The lesson worth carrying to findings #5, #2, #3, #6, #7, #8

**Both findings were real; both were mischaracterised in ways that would have caused
damage if executed literally.**

- **#1** was billed "standalone deletion, best warm-up." In fact five *working* reports
  (400/392/314/261/112 lines) share the same contract. Executed on the audit's framing,
  this deletes five live features. It was a subclass cull. `ReportRegistry`,
  `BuildsReportSnapshot`, `ReportDefinitionData`, `AbstractCoreReportPage` all survive.
- **#4**'s step list mislocated two classes (`RunBulkPublicationTransitionAction` and
  `BulkPublicationPreviewData` are in `packages/admin`, not Core) and prescribed a test
  command scoped too narrowly to catch the contract test its own change necessarily broke.

Both were marked ✅ **verified** in the roadmap. The *finding* was verified; the *shape*
was not. **Treat every remaining finding's shape as audit-trusted, even where the finding
is marked verified.** #2 is flagged as the highest source-of-truth risk in the audit;
#7 is gated on external-consumer breakage.

**The recurring failure mode was test SCOPE, not test count.** Three separate times a
confidently-reported green was scoped too narrowly to see what it should have caught:
- `BulkRevertPagesToDraftAction` was fully rewritten with **zero tests anywhere** —
  invisible behind "45 passed".
- `BulkSchedulePagesBulkActionTest` lives outside the path the plan's own command named.
- `PublicationTransitionDataTest` — a stable-transition contract registry that *any* new
  enum case necessarily breaks — sits in `tests/Unit/` while the plan ran only
  `tests/Feature/`. Two agents and a full code review missed it. Only the unscoped suite
  caught it.

When a plan hands you a test command, the command is a claim that needs checking too.

---

## Follow-ups surfaced (not in either finding's scope)

- **Exit gate for the publishing plan is NOT yet tickable** and was deliberately left
  unticked. `UnpublishPageAction.php:34` and `CancelScheduledPageUnpublishAction.php:33`
  still write date columns directly (both verified byte-identical to base, no production
  callers — they look like dead legacy superseded by `UnpublishRecordAction` /
  `CancelScheduledRecordUnpublishAction`).
- **`PagePublishSentinel` could not be deleted** — 2 live callers at `CreatePage.php:252`
  and `CreatePageAction.php:157` write `visible_from` directly on the creation path.
  Retiring those is the prerequisite.
- `HasImpersonation` has a pre-existing `trait.unused` PHPStan error, not in the known-4.
- `docs/superpowers/plans/2026-07-14-pre-release-hardening.md:82` is stale — it says
  `BuildDemoInstallHealthReportAction` extends `BuildEmptyReportAction`. It does not
  (it is 314 lines of real logic). Left as-is: it is a completed `[x]` phase describing
  a historical problem.

---

## Roadmap remaining

Order per `docs/superpowers/specs/2026-07-17-cleanup-audit-roadmap-design.md`
(#1 and #4 now marked ✅ DONE there):

1. ~~#1 empty reports~~ ✅ · 2. ~~#4 publishing residual~~ ✅
3. **#5 marketplace façade** (fresh spec) · 4. #2 registries (riskiest)
5. #3 installer (large) · 6. #6 frontend contexts · 7. #7 UserResourceBridge (gated)
8. #8 Pest sharding (spike-first)

Each remaining finding needs its own design spec before execution — the roadmap says so,
and #1/#4 just demonstrated why. Per repo convention, plan execution is always
`superpowers:subagent-driven-development`, never inline.

**Session ledger with full detail:** `.superpowers/sdd/progress.md` in this worktree
(gitignored — it survives only as long as the worktree does).
