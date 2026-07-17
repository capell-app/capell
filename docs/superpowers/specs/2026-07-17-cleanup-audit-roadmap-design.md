# Over-Complexity Cleanup Roadmap — Design

**Date:** 2026-07-17
**Status:** Draft for review
**Scope:** Sequencing and ownership decisions for the eight-finding over-complexity audit, plus the smaller consolidation backlog.

> This is a **roadmap/index spec**, not an implementation plan. It records the *verified* state of each finding, classifies each as a residual (extend an existing plan) or fresh work (needs its own spec), and fixes an execution order. Each fresh finding gets its own spec → plan → execute cycle when we reach it. Two findings already have committed plans and are tracked here against real code state, not checkbox state.

---

## Guiding principle

The audit's own thesis: the highest-value cleanup is **removing duplicate ownership** (competing sources of truth, compatibility layers, half-finished migrations), not splitting large classes by line count. Every item below is judged by one test:

> Does this change make a second owner of some fact go away, or does it just move code around?

Items that only add indirection (trivial normalizer extraction, blanket Action-pattern replacement) are explicitly **out of scope**.

---

## Evidence status

Findings are marked with how their current state was established:

- **✅ code-verified** — the relevant files were read this session and the audit's claim confirmed or corrected against source.
- **⚠️ audit-trusted** — taken from the audit as written; not yet re-checked against code. Must be verified at the start of that finding's own spec before any deletion.

Nothing gets deleted on audit-trust alone. Verification is the first task of each fresh spec.

---

## Verified findings table

| # | Finding | Priority | Evidence | Real state | Classification |
|---|---|---|---|---|---|
| 1 | Eight empty reports registered as real features | P2 | ✅ verified | `BuildEmptyReportAction::handle()` returns only `key`+`emptyState`; registered + default-enabled. Real. | **Fresh (small).** Standalone deletion, no competing owner. Best warm-up. |
| 4 | Publishing state-machine migration incomplete | P2 | ✅ verified | Core boundary + single-record adapters + bulk-publish preview **all landed**. Residual: 3 bulk actions still write dates directly; they were never in the plan. `PagePublishSentinel` is a *delegating alias*, not a duplicate. | **Residual.** Extend existing publishing plan (Task 7). First concrete win. |
| 5 | Marketplace querying + install policy spread across façades | P2 | ✅ verified | The existing *policy/cache* plan is **fully landed** (typed request DTO, policy-evidence migration, `InstallMarketplaceExtensionAction::handle(MarketplaceInstallRequestData)`, translation-ownership resolvers). But that plan solved a **different problem**. The audit's #5 — façade layering (`MarketplaceBrowser`→`MarketplaceCatalogueTable`→`MarketplaceCatalogueRecordProvider`) and URL-trust duplication (`RecordProvider` + `InstallActionPresenter`) — is **untouched**. | **Fresh.** New spec for structural consolidation; existing plan does not cover it. |
| 2 | Two package registries + two manifest models | P2 | ⚠️ audit-trusted | `CapellPackageRegistry` built, then copied into 851-line `HasPackages`; `PackageData` supports legacy `ManifestData` + v3 `CapellManifestData`. | **Fresh.** Highest-risk source-of-truth merge. Own spec. |
| 3 | Installer implements the same feature several times | P2 | ⚠️ audit-trusted | `InstallController` + `BuildInstallerPageDataAction` duplicate catalogue/options; 1,583-line Blade; 1,952-line JS; tests assert JS source strings. | **Fresh.** Own spec. Large; may itself decompose (service boundary / Blade component / JS modules). |
| 6 | Too many frontend context types + pipeline round-trip | P3 | ⚠️ audit-trusted | `FrontendState` (mutable) + `Data\FrontendContext` (immutable) + `CapellFrontendContext` + second `Support\Context\FrontendContext`; `BuildContextStep`→`CommitContextStep` copy round-trip. | **Fresh.** Own spec. Verify macro public-API claim before removing the wrapper. |
| 7 | User-resource extension runs four parallel APIs | P3 | ⚠️ audit-trusted | `UserResourceBridge` exists but `UserResourceBridgeResolver` still runs legacy form/table extenders; `AdminSchemaExtensionPipeline` runs schema extenders separately. | **Fresh (gated).** External-consumer hazard — see risk note. Own spec. |
| 8 | Pest sharding is a bespoke maintenance subsystem | P3 | ⚠️ audit-trusted | `composer.json` patches Pest vendor code via `patch-pest-shards.php`; custom 337-line runner + timing updater + PR workflow. | **Fresh (spike-first).** Benchmark native sharding before deleting anything. Own spec. |

### Consolidation backlog (P4, all ⚠️ audit-trusted)

Small, safe, no competing-owner risk. Batch these opportunistically; each is a single focused commit, not a spec:

- Centralize dashboard-widget registration shared by `AdminServiceProvider` + `CapellAdminPlugin`.
- Extract one header-navigation node builder from load-children + search actions.
- Move public-pageable morph-type resolution into one Core resolver.
- Share signed-URL query canonicalization between Core + Frontend.
- Make package-readiness reporting consume the existing capability graph.
- Share installed-extension record presentation between the Extensions page + widget.

---

## Risk notes (things that change how a finding is executed)

- **#7 external consumers (hard gate).** Composer consumers of the legacy user-resource extender APIs cannot be discovered statically. Boost skills explicitly target third-party consumers. Before removing any legacy resolver loop, tag, or Core alias, the #7 spec **must** enumerate and check known external alpha packages. Treat "no in-repo caller" as necessary but not sufficient.
- **#8 upstream dependency.** The Pest vendor patch is a documented, legitimate workaround. Deletion is conditional on the upstream issue being resolved *and* native sharding benchmarking at parity. If not resolved: pin the supported Pest range and document an explicit removal condition rather than deleting.
- **#2 source-of-truth merge is the riskiest.** Retiring legacy `ManifestData` touches install/enable state read across packages. Sequenced late, after the lower-risk wins build confidence.
- **Checkbox state is not evidence.** Both existing plans have every box unchecked despite ~90–100% completion (ticking-on-completion is a known lapse here). All "done" claims in this roadmap are from reading source, never from checkboxes.

---

## Execution order

Ordered by *risk-adjusted value*: safe removals first, finish in-flight migrations, then the source-of-truth merges, then the large structural reworks, then the tooling spike.

1. **#1 — Remove empty reports.** Standalone deletion; no competing owner. (Fresh, small.)
2. **#4 — Finish publishing bulk-action residual.** Extend the existing plan (Task 7): add Core `CancelSchedule` transition, convert the three bulk actions to adapters. **In progress this session.**
3. **#5 — Marketplace façade + URL-trust consolidation.** Fresh spec; one catalogue query service, one URL-trust policy, table = presentation only.
4. **#2 — Consolidate package registries + manifest models.** Fresh spec; `CapellPackageRegistry` sole source, retire `ManifestData`. Highest source-of-truth risk — do after confidence built.
5. **#3 — Installer catalogue/options boundary.** Fresh spec; may decompose into service / Blade component / JS-module sub-specs.
6. **#6 — Collapse frontend contexts.** Fresh spec; remove commit round-trip, keep one immutable snapshot.
7. **#7 — Standardize on `UserResourceBridge`.** Fresh spec; external-consumer gate first.
8. **#8 — Resolve Pest sharding.** Benchmark spike; delete or pin-and-document.

Backlog items slot in opportunistically alongside whichever finding touches the same package.

---

## Exit definition for the whole roadmap

- Every finding is either shipped or has a committed spec explaining an explicit decision to defer.
- No finding is closed on audit-trust; each `⚠️` was code-verified before action.
- `PLANS_STATUS.md` (once located/created) and each plan's checkboxes reflect real state.
- No new indirection was added that fails the guiding-principle test.
