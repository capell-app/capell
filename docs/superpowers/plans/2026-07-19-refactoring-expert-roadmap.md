# TODO — Refactoring Expert: Capell Core, Installer, and Marketplace

> Audit date: 2026-07-19. Source baseline: `f02a0d9be` plus the working-tree state inspected during the
> audit. Existing unrelated SiteSpec, package-catalogue, installer-page-data, and frontend-contributor
> changes are excluded from this roadmap. This document is self-contained and supersedes the stale
> provider/manager plan previously stored here.

## Context

- [x] **RF-CTX-1.1 [Scope and owner priorities]** — Prioritise behavior-preserving maintainability and
      readability, then reduce regression-prone complexity on install and marketplace paths. Preserve public
      HTML, HTTP/JSON response shapes, route names, translations, install ordering, entitlement decisions,
      append-only marketplace ledgers, and existing extension interfaces. Performance work is in scope only
      where the refactor can retain or improve a measurable HTTP/query/iteration count.

- [x] **RF-CTX-1.2 [Metric method]** — Production PHP under `packages/*/src` was parsed with the already
      installed `nikic/php-parser`. Class and method LOC use AST start/end lines. Cyclomatic complexity is
      `1 + if/elseif/loop/catch/non-default-case/ternary/boolean/null-coalescing/match-arm decisions`. Coupling is the
      count of unique referenced class names. Cohesion is an LCOM4-style approximation joining methods that
      share instance fields or call each other; values above `1` are a review signal, not automatic proof of a
      bad design. Git churn is the number of commits touching the file in the available branch history.

- [x] **RF-CTX-1.3 [Repository threshold scan]** — The audit snapshot contained 1,734 production classes
      and 7,867 methods. There were 163 classes over 200 lines and 1,540 methods over 20 lines; the initial
      decision-path scan found 242 methods over complexity 10. These thresholds are triage signals. Applying
      them blindly to Data normalisers, declarative Filament schemas, and composition-root providers would
      create shallow modules and more indirection, so this plan targets high-change orchestration code where
      the metrics align with real responsibility mixing.

### Current metric baselines

| ID            | Target                                  | Class LOC | Methods | Average method LOC | Methods >20 | Max complexity | Coupling | LCOM4 | Churn |
| ------------- | --------------------------------------- | --------: | ------: | -----------------: | ----------: | -------------: | -------: | ----: | ----: |
| RF-METRIC-1.1 | `InstallCommand`                        |     1,055 |      43 |               21.3 |          12 |             27 |       70 |     4 |    11 |
| RF-METRIC-1.2 | `InstallController`                     |       555 |      31 |               16.9 |           7 |             21 |       35 |    15 |     5 |
| RF-METRIC-1.3 | `MarketplaceExtensionsBrowser`          |       961 |      44 |               18.0 |           9 |             29 |       45 |     4 |     4 |
| RF-METRIC-1.4 | `MarketplaceCatalogueRecordProvider`    |     1,049 |      56 |               16.0 |          11 |             16 |       62 |     2 |     6 |
| RF-METRIC-1.5 | `MarketplaceClient`                     |     1,085 |      48 |               19.5 |          15 |             20 |       58 |     2 |     2 |
| RF-METRIC-1.6 | `InstallMarketplaceExtensionAction`     |       688 |      23 |               26.9 |          11 |             27 |       51 |     2 |     4 |
| RF-METRIC-1.7 | `BuildExtensionOperationsSummaryAction` |       642 |      31 |               18.2 |           8 |             36 |       55 |     2 |     2 |

- [x] **RF-CTX-1.4 [Target metrics]** — Re-measure with the same AST rules after each complete target.

| ID            | Target after the complete target                |       LOC | Max method LOC | Max complexity | Coupling |   LCOM4 |
| ------------- | ----------------------------------------------- | --------: | -------------: | -------------: | -------: | ------: |
| RF-TARGET-1.1 | `InstallCommand`                                |      ≤200 |            ≤20 |            ≤10 |      ≤25 |      ≤2 |
| RF-TARGET-1.2 | `InstallController`                             |      ≤200 |            ≤20 |            ≤10 |      ≤20 |      ≤5 |
| RF-TARGET-1.3 | `MarketplaceExtensionsBrowser`                  |      ≤200 |            ≤20 |            ≤10 |      ≤25 |      ≤2 |
| RF-TARGET-1.4 | Catalogue coordinator and each extracted module | ≤200 each |            ≤20 |            ≤10 | ≤20 each | ≤2 each |
| RF-TARGET-1.5 | Each protocol-specific marketplace client       | ≤200 each |            ≤20 |            ≤10 | ≤20 each | ≤2 each |
| RF-TARGET-1.6 | `InstallMarketplaceExtensionAction`             |      ≤200 |            ≤20 |            ≤10 |      ≤25 |      ≤1 |
| RF-TARGET-1.7 | Summary aggregator and package builder          | ≤200 each |            ≤20 |            ≤10 | ≤25 each | ≤2 each |

- [x] **RF-CTX-1.5 [Highest-complexity methods]** — The actionable peaks are
      `BuildExtensionOperationsSummaryAction::packageSummary()` (complexity 36, 91 LOC),
      `MarketplaceExtensionsBrowser::marketplaceSelectionReview()` (29, 165),
      `InstallCommand::handle()` (27, 268),
      `InstallMarketplaceExtensionAction::handle()` (27, 149), and
      `InstallController::runStep()` (21, 103). Higher raw scores in API/manifest Data factories were reviewed
      and retained as input-normalisation seams rather than treated as polymorphism candidates.

### Code smells and severity

- [x] **RF-SMELL-1.1 [High — UI/domain responsibility mixing]** — `InstallController` owns session
      sequencing, preflight execution, install execution, report assembly, and HTTP negotiation.
      `MarketplaceExtensionsBrowser` owns recursive dependency resolution, eligibility presentation, install
      option normalisation, hosted-flow decisions, notifications, and redirects. Both make framework adapters
      the primary domain test surface.

- [x] **RF-SMELL-1.2 [High — long orchestration methods]** — `InstallCommand::handle()`,
      `InstallMarketplaceExtensionAction::handle()`, and `marketplaceSelectionReview()` interleave input
      resolution, policy decisions, side effects, presentation, and failure mapping. The code is covered but a
      change requires understanding several abstraction levels at once.

- [x] **RF-SMELL-1.3 [High — broad marketplace modules]** — `MarketplaceClient` combines four protocols
      (connection/install-flow, catalogue, authorization/telemetry, and heartbeat verification).
      `MarketplaceCatalogueRecordProvider` combines remote pagination, local inventory state, filter parsing,
      compatibility, price/rating formatting, image safety, and table record projection.

- [x] **RF-SMELL-1.4 [Medium — missing parameter object]** —
      `RecordMarketplaceInstallAttemptAction::handle()` has 18 parameters and six call sites. Four callers in
      `InstallMarketplaceExtensionAction` rebuild overlapping policy, actor, source, eligibility, context, and
      failure arguments. The immutable ledger contract deserves one typed input.

- [x] **RF-SMELL-1.5 [Medium — mutable per-run action state]** —
      `InstallMarketplaceExtensionAction` stores `$activeRequest` and `$activePolicyEvidence` on the resolved
      Action object. Both values belong to a single execution context and should travel explicitly through a
      Data object, especially under long-lived workers.

- [x] **RF-SMELL-1.6 [Medium — exact duplication]** — The AST scan found 69 exact duplicate method groups
      of at least eight lines. The first actionable duplicates are the identical 27-line `parseListOption()` in
      `InstallCommand`/`SetupCommand`, the 23-line doctor result renderer, repeated marketplace alert-signature
      verification, and duplicated extension-management URL safety logic. Small Data coercion helpers and
      framework-required resource methods are intentionally not centralised without a stronger locality gain.

- [x] **RF-SMELL-1.7 [Critical findings]** — No new critical correctness or data-leak defect was validated
      by this refactoring audit. Potential defects discovered during implementation must stop the structural
      slice and be fixed in a separate behavior-change commit.

- [x] **RF-SMELL-1.8 [Medium — feature envy and inappropriate intimacy]** — The marketplace browser reads
      presentation-record internals and Composer installation state to make dependency decisions;
      `BuildExtensionOperationsSummaryAction` reaches into routing, schema, package, marketplace, health, and
      runtime modules to project one package. Move each decision to the module that owns the required data.

### Dependencies and architectural constraints

- [x] **RF-DEP-1.1 [Installer flow]** — HTTP routes call `InstallController`, which coordinates
      `InstallerSessionRepository`, `InstallerPreflight`, `RunInstallAction`/`RunInstallStepAction`,
      `AdminUserModelGuard`, and `InstallStepResponse`. The refactor must preserve session ownership, lock
      replacement, completed-step replay, HTTP 409/410 behavior, CSRF refresh fields, peak-memory recording,
      and the queue/synchronous/step modes.

- [x] **RF-DEP-1.2 [Console flow]** — `InstallCommand` is both a Symfony command and the
      `InstallOrchestrationHost`. `InstallInputFactory`, `InstallUserPrompter`, `InstallPackageSetComposer`, and
      `InstallPostInstallOptionResolver` already provide useful seams; deepen those instead of adding another
      generic command framework.

- [x] **RF-DEP-1.3 [Marketplace flow]** — `MarketplaceExtensionsBrowser` reads records from
      `MarketplaceCatalogueRecordProvider`, which reads API Data through `MarketplaceClient`. Installation is
      delegated through `MarketplaceCatalogueTable` to `InstallMarketplaceExtensionAction`. Selection review
      and install authorization are related but distinct domain modules and must remain separate.

- [x] **RF-DEP-1.4 [Extension operations]** — `BuildExtensionOperationsSummaryAction` is a shared source
      for dependency, runtime, update, diagnostics, audit, dashboard, and management-entry modules. Its output
      Data and request-cache key are stable seams; consumers should not be rewritten during extraction.

- [x] **RF-DEP-1.5 [Pattern decision]** — Use Extract Class, Compose Method, Introduce Parameter Object,
      Factory, and guard clauses. Do not add speculative interfaces, inheritance, Observer, Decorator, or
      Strategy hierarchies: the current variations do not have two concrete adapters. Queue/synchronous/step
      installer modes can delegate to three Actions through an enum/match without a new interface.

- [x] **RF-DEP-1.6 [Modernisation decision]** — No JavaScript/TypeScript module is in the selected
      production scope, so callback-to-async/await, optional chaining, destructuring, and TypeScript interface
      work are not applicable. PHP changes must retain strict types, explicit return/parameter types, readonly
      Data where appropriate, and current project syntax conventions.

### Test coverage inventory

The counts below are static Pest test declarations in the named current files, not a fresh pass claim.
`coverage/clover.xml` is dated 2026-07-12 and is too stale for current line-coverage decisions.

| ID          | Seam                         |                                              Existing tests | Behavior already characterised                                                                                | Gap before extraction                                                               |
| ----------- | ---------------------------- | ----------------------------------------------------------: | ------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| RF-TEST-1.1 | Web installer controller     |                                                         131 | page/session access, queue/sync/step runs, locks, reports, preflight, CSRF, success/removal                   | No direct step-runner or report-builder tests because logic lives in the controller |
| RF-TEST-1.2 | Console install              |                                                          92 | options, prompts, profiles, fresh/demo/package/theme/user choices, failure and handoff paths                  | No typed resolved-console-input contract; `handle()` is the only integrated seam    |
| RF-TEST-1.3 | Marketplace browser/review   |                                                          38 | direct/transitive dependencies, beta acknowledgement, hosted flow, filters, selection and local-state privacy | Review Data is an anonymous 17-key array and cannot be tested independently         |
| RF-TEST-1.4 | Catalogue record provider    |                 68 direct table tests plus browser coverage | pagination, stale cache, filtering, local inventory, safety, compatibility and action state                   | Mapper/query responsibilities are only indirectly testable                          |
| RF-TEST-1.5 | Marketplace client           |                                                          31 | connection, catalogue, context, heartbeat, authorization, telemetry and purchase failures                     | Protocol groups cannot be run or faked independently                                |
| RF-TEST-1.6 | Marketplace install action   | 10 focused action/policy tests plus table integration tests | maturity, entitlement, authorization, ledger and queue outcomes                                               | UI notification behavior and install decision logic share one Action                |
| RF-TEST-1.7 | Extension operations summary |                                                          11 | manifests, drift, alerts, safe links/images and downstream derived surfaces                                   | Per-package projection has no direct test seam                                      |

- [x] **RF-TEST-1.8 [Audit-time verification boundary]** — A focused 381-test invocation covering these
      files was started, then stopped with exit 130 before results because unrelated Pest processes entered the
      same shared checkout. No pass/fail claim is made. Run the commands below only when no other shared-database
      suite is active.

## Refactoring Plan

- [ ] **RF-PLAN-0.1 [Characterisation baseline]**
    - **Target:** Existing tests named in `RF-CMD-1.1` and new direct tests for each extracted Action/Data pair.
    - **Reason:** Preserve observable routes, payloads, install order, ledger rows, notifications, and cache
      behavior before changing ownership.
    - **Risk:** Low. Do not rewrite existing assertions to follow implementation details.
    - **Priority:** 1.
    - **Effort:** Small; one baseline commit only if genuinely missing behavior tests are added.
    - **Success metric:** Existing assertions unchanged; every new module is tested through its public
      interface; fresh focused coverage is recorded before its production extraction.

- [ ] **RF-PLAN-1.1 [Introduce Parameter Object]**
    - **Target:** `RecordMarketplaceInstallAttemptAction`, its six call sites, and new
      `MarketplaceInstallAttemptData`.
    - **Reason:** Replace an 18-parameter write interface and duplicated ledger-context assembly with one
      immutable typed boundary while preserving the one-app-writer and append-only contracts.
    - **Risk:** Medium; a missing field could silently change audit evidence. Compare complete persisted rows
      in tests before and after.
    - **Priority:** 1; preparatory for `RF-PLAN-2.1`.
    - **Effort:** Medium; two independently revertible commits (Data + Action, then call-site migration).
    - **Success metric:** Action parameters 18 → 2 (`Data`, optional user); all six callers migrated; database
      attributes and enum values byte-for-byte equivalent.

- [ ] **RF-PLAN-1.2 [Extract Class + Data boundary]**
    - **Target:** `MarketplaceExtensionsBrowser::marketplaceSelectionReview()` and selection-only helpers;
      add `BuildMarketplaceSelectionReviewAction`, `ExecuteMarketplaceSelectionAction`, and typed review/
      execution Data.
    - **Reason:** Move recursive dependency expansion and install-impact policy out of Livewire while keeping
      authorization, notifications, redirects, and rendering in the UI adapter.
    - **Risk:** Medium-high; transitive dependency and beta/entitlement decisions are commercial contracts.
      Pin ordering and every returned key before extraction.
    - **Priority:** 1; recommended first behavior-preserving extraction.
    - **Effort:** Large; four slices (review Data/Action, execution Data/Action, then Livewire delegation).
    - **Success metric:** `marketplaceSelectionReview()` 165 → ≤15 LOC and complexity 29 → ≤3;
      component ≤200 LOC; extracted methods ≤20 LOC/complexity ≤10; returned array and order unchanged.

- [ ] **RF-PLAN-1.3 [Extract Actions + Compose Method]**
    - **Target:** `InstallController::store()`, `runStep()`, `report()`, and `prepareStepBasedInstall()`; add
      `StartInstallerRunAction`, `RunInstallerStepAction`, `BuildInstallerReportAction`, and typed result Data.
    - **Reason:** Make the controller an HTTP adapter and put install session transitions behind an Action
      interface that can be tested without route/request setup.
    - **Risk:** High; session access, replay, status codes, CSRF and failure payloads are installer contracts.
      Extract one route method per commit.
    - **Priority:** 1.
    - **Effort:** Large; four to five focused commits.
    - **Success metric:** Controller ≤200 LOC; every route method ≤20 LOC and complexity ≤10; result Data is
      the sole input to `InstallStepResponse`; all 131 existing tests retain their assertions.

- [ ] **RF-PLAN-1.4 [Extract Class + Move Method]**
    - **Target:** `InstallCommand::handle()` plus option/profile/package/theme/user resolution; add
      `ResolveConsoleInstallInputAction`, `ResolvedConsoleInstallData`, and a focused console orchestration
      host/presenter module. Move the exact `parseListOption()` clone to one CLI parser.
    - **Reason:** Keep the Symfony command responsible for input/output dispatch while Actions/Data own the
      resolved install request and existing `OrchestrateInstallAction` owns execution.
    - **Risk:** High; interactive/non-interactive defaults and destructive confirmation order are public CLI
      behavior. Preserve prompt order and exception strings.
    - **Priority:** 1, after the web installer extraction so shared install concepts are clear.
    - **Effort:** Large; four to six focused commits.
    - **Success metric:** `handle()` 268 → ≤20 LOC and complexity 27 → ≤5; command ≤200 LOC; every extracted
      class ≤200 LOC; `InstallInputData` equality asserted for all 92 existing scenarios.

- [ ] **RF-PLAN-2.1 [Extract Class + explicit execution context]**
    - **Target:** `InstallMarketplaceExtensionAction`; add `MarketplaceInstallExecutionData`,
      `ResolveMarketplaceInstallDecisionAction`, and `SendMarketplaceInstallOutcomeNotificationAction`.
    - **Reason:** Separate entitlement/maturity/authorization decisions from Filament notifications and remove
      mutable `$activeRequest`/`$activePolicyEvidence` state.
    - **Risk:** High; checkout, authorization, telemetry, queueing and ledger outcomes must remain immutable.
      Keep `InstallMarketplaceExtensionAction::run()` as the compatibility entrypoint until all callers migrate.
    - **Priority:** 2; requires `RF-PLAN-1.1`.
    - **Effort:** Large; four focused commits.
    - **Success metric:** Action ≤200 LOC; `handle()` ≤20 LOC/complexity ≤5; zero mutable per-run properties;
      domain Actions do not import Filament notifications; identical redirect/notification/ledger outcomes.

- [ ] **RF-PLAN-2.2 [Factory + Extract Class + Move Method]**
    - **Target:** `MarketplaceCatalogueRecordProvider`; add `MarketplaceCatalogueQueryFactory`,
      `MarketplaceCataloguePageReader`, and `MarketplaceCatalogueRecordMapper`.
    - **Reason:** Separate filter/query normalisation, remote page/backfill traversal, and local/presentation
      projection. These are cohesive modules with different change reasons and test surfaces.
    - **Risk:** Medium-high; local-state privacy, hidden records, totals and page backfill are coupled contracts.
    - **Priority:** 2.
    - **Effort:** Large; three to four commits after direct characterisation tests.
    - **Success metric:** Coordinator ≤200 LOC; each extracted class ≤200 LOC; max method complexity ≤10;
      same API requests, page totals, record order, labels, safe URLs, and local-state redaction.

- [ ] **RF-PLAN-2.3 [Extract Class by protocol]**
    - **Target:** `MarketplaceClient`; split connection/install-flow, catalogue, authorization/telemetry, and
      heartbeat into concrete clients. Share only a small request/response implementation module; add no
      interface until a second adapter exists.
    - **Reason:** Each protocol has different signing, caching, validation, and error modes. The current broad
      interface forces unrelated callers/tests to know the whole implementation.
    - **Risk:** High; signing inputs, cache keys, endpoint paths, exception text, 404 behavior and approval URL
      validation are contracts. Verify the stable-extension catalogue before deleting the old class.
    - **Priority:** 3; do after record-provider ownership is clear.
    - **Effort:** Large; one protocol per commit.
    - **Success metric:** Each client ≤200 LOC and method complexity ≤10; no endpoint/cache/signature/error
      drift; callers depend only on the protocol they use; `check:stable-extension-api` remains green.

- [ ] **RF-PLAN-2.4 [Introduce Parameter Object + Extract Class]**
    - **Target:** `BuildExtensionOperationsSummaryAction::packageSummary()` and its URL/image/health helpers;
      add `ExtensionOperationPackageContextData` and `BuildExtensionOperationPackageAction`.
    - **Reason:** Keep summary aggregation/cache ownership local while moving per-package projection behind a
      directly testable Action.
    - **Risk:** Medium; downstream dependency/runtime/update/audit modules consume the exact Data fields.
    - **Priority:** 2.
    - **Effort:** Medium; two to three commits.
    - **Success metric:** Aggregator ≤200 LOC; package builder ≤200 LOC; `packageSummary()` complexity 36 →
      delegated call ≤3; `ExtensionOperationsSummaryData` remains identical for all fixtures.

- [ ] **RF-PLAN-3.1 [Targeted duplicate consolidation]**
    - **Target:** CLI list-option parser, doctor result presenter, marketplace alert-signature verifier, and
      extension management-link resolver.
    - **Reason:** These clones contain behavior or safety policy that can drift. Do not centralise trivial Data
      coercers or framework boilerplate merely to reduce a duplicate count.
    - **Risk:** Low-medium; move one exact clone group per commit and retain caller-level tests.
    - **Priority:** 4.
    - **Effort:** Small per clone group.
    - **Success metric:** Four high-value exact clone groups → zero; no generic `Utils`/`Helpers` class; each
      replacement module has a domain name and at least two callers.

- [ ] **RF-PLAN-4.1 [Guardrails and documentation]**
    - **Target:** Package Arch tests, `docs/packages/installer-extension-contracts.md`,
      `docs/packages/marketplace-extension-contracts.md`, and relevant development command docs.
    - **Reason:** Prevent controllers/Livewire modules from reacquiring domain execution and record the new
      module interfaces for maintainers.
    - **Risk:** Low.
    - **Priority:** 2; land each guard immediately after the rule becomes true, and use documentation to
      close each phase.
    - **Effort:** Small.
    - **Success metric:** Installer controllers no longer directly use core run Actions/jobs; the marketplace
      browser no longer uses Composer runtime inspection or install-attempt models; marketplace decision
      Actions do not use Filament notification classes.

## Refactoring Items

- [ ] **RF-ITEM-1.1 [Controller-owned install step → Action result]**
    - **Pattern Applied:** Extract Class, Compose Method, Data boundary.
    - **Before:** `InstallController::runStep()` reads session state, validates sequence, executes preflight or
      a step, records memory/status, clears locks, and constructs HTTP responses in 103 LOC/complexity 21.
    - **After:** `RunInstallerStepAction` owns the state transition and returns `InstallerStepResultData`;
      `InstallStepResponse` maps that result to the unchanged JSON contract.
    - **Metrics:** Controller 555 → ≤200 LOC; route method 103 → ≤15 LOC; complexity 21 → ≤3.

- [ ] **RF-ITEM-1.2 [Anonymous selection array → typed review module]**
    - **Pattern Applied:** Extract Class, Introduce Parameter Object.
    - **Before:** Livewire builds and caches a 17-key array, traverses dependencies, queries missing records,
      derives beta/price/impact state, and recursively calls itself to return the cached value.
    - **After:** `BuildMarketplaceSelectionReviewAction` returns `MarketplaceSelectionReviewData`; Livewire
      caches the Data, delegates install-flow execution, and exposes `toArray()` only at the view boundary.
    - **Metrics:** Component 961 → ≤200 LOC; method 165 → ≤15 LOC; complexity 29 → ≤3; duplicated
      array-shape PHPDoc 2 → 0.

- [ ] **RF-ITEM-1.3 [18 ledger arguments → immutable record input]**
    - **Pattern Applied:** Introduce Parameter Object.
    - **Before:** Six call sites pass up to 18 named arguments and reconstruct actor/source/policy/context.
    - **After:** One `MarketplaceInstallAttemptData` captures the immutable write contract; the Action adds only
      persistence timestamp and authenticated-user projection.
    - **Metrics:** Parameters 18 → 2; four repeated argument blocks in the install Action → 0.

- [ ] **RF-ITEM-1.4 [Command god method → resolved console input]**
    - **Pattern Applied:** Extract Class, Compose Method, Move Method.
    - **Before:** `handle()` mixes destructive confirmation, package/theme/user choices, post-install options,
      DTO construction, orchestration, presentation and failure mapping.
    - **After:** a resolver returns `ResolvedConsoleInstallData`; the command chooses plan vs execute and the
      existing orchestration Action performs the install.
    - **Metrics:** 268 → ≤20 LOC; complexity 27 → ≤5; exact list parser copies 2 → 1.

- [ ] **RF-ITEM-1.5 [UI-aware install Action → decision + presentation]**
    - **Pattern Applied:** Extract Class, Replace Mutable State with Parameter.
    - **Before:** one Action imports Filament, writes ledger entries, authorizes, queues, dispatches telemetry,
      stores call state, and returns redirect strings.
    - **After:** decision/execution Data flows through pure domain Actions; a notification Action presents the
      outcome; the existing public entrypoint remains a thin compatibility module.
    - **Metrics:** 688 → ≤200 LOC; mutable run fields 2 → 0; domain-to-Filament imports → 0.

- [ ] **RF-ITEM-1.6 [Broad catalogue provider → three deep modules]**
    - **Pattern Applied:** Factory, Extract Class, Move Method.
    - **Before:** one 1,049-line module owns filters, API traversal, local state, compatibility and rendering.
    - **After:** query factory, page reader and record mapper each expose one cohesive interface; the provider
      coordinates them for existing callers.
    - **Metrics:** coordinator ≤200 LOC; max complexity 16 → ≤10; no change in remote request count.

- [ ] **RF-ITEM-1.7 [Summary projection wall → per-package Action]**
    - **Pattern Applied:** Introduce Parameter Object, Extract Class.
    - **Before:** a six-parameter, complexity-36 method derives every field and reaches into routing, schema,
      package, marketplace, health and runtime state.
    - **After:** an immutable context feeds one package builder; summary aggregation/cache ownership stays in
      the original Action.
    - **Metrics:** method 91 → ≤10 LOC; complexity 36 → ≤3; aggregator ≤200 LOC.

## Proposed Code Changes

- [ ] **RF-PATCH-1.1 [Installer step result seam]** — Implement the following shape; preserve current
      response keys/status codes in `InstallStepResponse` rather than duplicating them in the Action.

```diff
diff --git a/packages/installer/src/Http/Controllers/InstallController.php b/packages/installer/src/Http/Controllers/InstallController.php
@@
-public function runStep(RunInstallStepRequest $request): JsonResponse
-{
-    // session lookup, ordering, preflight, execution, persistence, response mapping
-}
+public function runStep(RunInstallStepRequest $request): JsonResponse
+{
+    abort_unless($this->canAccessInstall($request, (string) $request->validated('install_id')), 404);
+
+    return $this->stepResponse->fromResult(RunInstallerStepAction::run(
+        installId: (string) $request->validated('install_id'),
+        stepKey: (string) $request->validated('step'),
+    ));
+}
diff --git a/packages/installer/src/Data/InstallerStepResultData.php b/packages/installer/src/Data/InstallerStepResultData.php
new file mode 100644
@@
+final readonly class InstallerStepResultData
+{
+    /** @param array<string, mixed> $additional */
+    public function __construct(
+        public string $installId,
+        public string $status,
+        public ?string $currentStep,
+        public ?string $nextStep,
+        public string $logPath,
+        public ?string $error = null,
+        public int $httpStatus = 200,
+        public array $additional = [],
+    ) {}
+}
```

- [ ] **RF-PATCH-1.2 [Typed marketplace selection review]** — Keep the public Livewire array shape while
      moving construction and caching to typed modules.

```diff
diff --git a/packages/marketplace/src/Filament/Livewire/MarketplaceExtensionsBrowser.php b/packages/marketplace/src/Filament/Livewire/MarketplaceExtensionsBrowser.php
@@
-private ?array $resolvedMarketplaceSelectionReview = null;
+private ?MarketplaceSelectionReviewData $resolvedMarketplaceSelectionReview = null;
@@
 public function marketplaceSelectionReview(): array
 {
-    // recursive dependency resolution and 17-key array construction
+    return ($this->resolvedMarketplaceSelectionReview ??= BuildMarketplaceSelectionReviewAction::run(
+        selectedComposerNames: $this->normalizedSelectedMarketplaceComposerNames(),
+        lockedKind: $this->lockedKind,
+        includeLocalExtensionState: $this->includeLocalExtensionStateForBrowser(),
+        canManageExtensions: ExtensionsPage::canManageExtensions(),
+    ))->toArray();
 }
diff --git a/packages/marketplace/src/Actions/BuildMarketplaceSelectionReviewAction.php b/packages/marketplace/src/Actions/BuildMarketplaceSelectionReviewAction.php
new file mode 100644
@@
+final class BuildMarketplaceSelectionReviewAction
+{
+    use AsFake;
+    use AsObject;
+
+    /** @param list<string> $selectedComposerNames */
+    public function handle(
+        array $selectedComposerNames,
+        ?string $lockedKind,
+        bool $includeLocalExtensionState,
+        bool $canManageExtensions,
+    ): MarketplaceSelectionReviewData {
+        // Compose focused methods: explicit records, dependency closure, totals, impact, final decision.
+    }
+}
```

- [ ] **RF-PATCH-1.3 [Marketplace install attempt input]** — Preserve model fillable keys and row values.

```diff
diff --git a/packages/marketplace/src/Actions/RecordMarketplaceInstallAttemptAction.php b/packages/marketplace/src/Actions/RecordMarketplaceInstallAttemptAction.php
@@
-public function handle(
-    string $extensionSlug,
-    string $extensionName,
-    string $composerName,
-    string $kind,
-    MarketplaceInstallIntentStatus $status,
-    // 13 more parameters
-): MarketplaceInstallAttempt {
+public function handle(
+    MarketplaceInstallAttemptData $attempt,
+    ?Authenticatable $user = null,
+): MarketplaceInstallAttempt {
     return MarketplaceInstallAttempt::query()->create([
-        'composer_name' => $composerName,
+        'composer_name' => $attempt->composerName,
         // one explicit mapping for every existing persisted field
     ]);
 }
```

- [ ] **RF-PATCH-1.4 [Console install resolution]** — Reuse the existing install factory/planner modules;
      do not create a second orchestration pipeline.

```diff
diff --git a/packages/core/src/Console/Commands/InstallCommand.php b/packages/core/src/Console/Commands/InstallCommand.php
@@
 public function handle(): int
 {
-    // 268 lines of option resolution and orchestration
+    $resolution = ResolveConsoleInstallInputAction::run(ConsoleInstallOptionsData::fromInput($this->input));
+
+    if ($resolution->exitCode !== null) {
+        return $resolution->exitCode;
+    }
+
+    return $resolution->planOnly
+        ? $this->finishPlanOnlyInstall($resolution->input)
+        : $this->runInstallOrchestrationFrom($resolution);
 }
```

- [ ] **RF-PATCH-1.5 [Package summary context]** — Keep aggregation and request caching in the current Action.

```diff
diff --git a/packages/admin/src/Actions/Extensions/BuildExtensionOperationsSummaryAction.php b/packages/admin/src/Actions/Extensions/BuildExtensionOperationsSummaryAction.php
@@
-private function packageSummary(
-    CapellManifestData $manifest,
-    ?PackageData $package,
-    ?CapellExtension $extension,
-    Collection $alerts,
-    bool $marketplaceAccountConnected,
-    array $contributions,
-): ExtensionOperationPackageData {
-    // 91 lines of projection
-}
+private function packageSummary(ExtensionOperationPackageContextData $context): ExtensionOperationPackageData
+{
+    return BuildExtensionOperationPackageAction::run($context);
+}
```

- [ ] **RF-PATCH-1.6 [No speculative pattern files]** — Do not add `*Strategy`, `*Observer`, `*Decorator`,
      `Abstract*`, `Base*`, or generic `Utils`/`Helpers` files unless a concrete second implementation/caller is
      present in the same slice and the deletion test shows that complexity would otherwise return to callers.

## Refactoring Safety

- [ ] **RF-SAFE-1.1 [Pre-refactoring]** — Freshness-check every target with `git status --short` and
      `git log -3 --oneline -- <target>`. Start only when the target paths have no uncommitted owner changes;
      preserve unrelated dirty paths. Record the focused test/coverage and metric baseline before production
      edits.
- [ ] **RF-SAFE-1.2 [During refactoring]** — Apply one named refactoring per commit, run its direct and
      caller-level tests immediately, and stop the slice on any unexplained behavior difference. Do not stack
      follow-up fixes on an unverified extraction.
- [ ] **RF-SAFE-1.3 [Post-refactoring]** — Re-run focused tests, static analysis, format, full phase gates,
      and the metric scan. Review the complete diff for public-interface drift and record any remaining debt.
- [ ] **RF-SAFE-1.4 [Communication]** — Each commit/PR records the pattern, before/after metrics, preserved
      contracts, verification commands/results, trade-offs, and rollback unit. Never describe planned or
      partially verified work as complete.

## Commands

- [ ] **RF-CMD-1.1 [Focused behavior baseline]** — Run serially when no other shared-database Pest process
      is active:

```bash
php -d memory_limit=2G -d max_execution_time=0 -d pcov.enabled=0 vendor/bin/pest \
  packages/installer/tests/Feature/InstallControllerTest.php \
  packages/installer/tests/Unit/InstallerSessionRepositoryTest.php \
  packages/core/tests/Feature/Commands/InstallCommandTest.php \
  packages/core/tests/Feature/Commands/InstallCommandPlanCoverageTest.php \
  packages/core/tests/Feature/Commands/InstallCommandWelcomeRouteTest.php \
  packages/core/tests/Unit/Console/InstallCommandOptionResolutionTest.php \
  packages/core/tests/Unit/Support/Install/Cli/InstallPackageSetComposerTest.php \
  packages/core/tests/Unit/Support/InstallInputFactoryTest.php \
  packages/marketplace/tests/Feature/Filament/MarketplaceExtensionsBrowserTest.php \
  packages/marketplace/tests/Feature/Filament/MarketplaceInstallImpactReviewTest.php \
  packages/marketplace/tests/Feature/Filament/MarketplaceCatalogueTableTest.php \
  packages/marketplace/tests/Feature/Services/MarketplaceClientTest.php \
  packages/marketplace/tests/Feature/Actions/InstallMarketplaceExtensionActionTest.php \
  packages/marketplace/tests/Feature/Actions/MarketplaceInstallMaturityPolicyTest.php \
  packages/admin/tests/Feature/Extensions/ExtensionOperationsSummaryTest.php \
  --compact --configuration=phpunit.xml
```

- [ ] **RF-CMD-1.2 [Per-slice static analysis and format]** — Example for the first installer extraction;
      repeat with the exact owned paths for each later slice and never include unrelated working-tree changes.

```bash
XDEBUG_MODE=off vendor/bin/phpstan analyse --memory-limit=4G --configuration=phpstan.neon \
  packages/installer/src/Actions/RunInstallerStepAction.php \
  packages/installer/src/Data/InstallerStepResultData.php \
  packages/installer/src/Http/Controllers/InstallController.php \
  packages/installer/src/Http/Responses/InstallStepResponse.php
vendor/bin/pint --test \
  packages/installer/src/Actions/RunInstallerStepAction.php \
  packages/installer/src/Data/InstallerStepResultData.php \
  packages/installer/src/Http/Controllers/InstallController.php \
  packages/installer/src/Http/Responses/InstallStepResponse.php
git diff --check -- \
  packages/installer/src/Actions/RunInstallerStepAction.php \
  packages/installer/src/Data/InstallerStepResultData.php \
  packages/installer/src/Http/Controllers/InstallController.php \
  packages/installer/src/Http/Responses/InstallStepResponse.php
```

- [ ] **RF-CMD-1.3 [Phase gates]** — Run sequentially after all focused checks are green:

```bash
composer test
composer preflight
```

- [ ] **RF-CMD-1.4 [Contract and browser gates]** — Required when the corresponding installer or
      marketplace phase changes documented extension seams or the rendered flow:

```bash
composer check:extension-surfaces
composer check:stable-extension-api
npm run test:installer-browser
npm run test:marketplace-install-flow:required
```

- [ ] **RF-CMD-1.5 [Performance parity]** — For catalogue slices, add HTTP fakes that assert request count,
      visited URLs, cache keys, and ordering. For installer slices, assert command/job/step invocation counts.
      Do not claim a wall-time improvement from structural refactoring; benchmark only if implementation changes
      a hot request path.

## Quality Assurance Task Checklist

- [ ] **RF-QA-1.1** All existing tests pass without changing existing behavior assertions; new tests exercise
      the extracted module interface rather than source text or private methods.
- [ ] **RF-QA-1.2** Each numbered plan slice is one focused, revertible commit. Structural and behavior
      changes never share a commit.
- [ ] **RF-QA-1.3** Before/after LOC, method length, complexity, coupling, and LCOM4 are remeasured and recorded
      in the commit/PR against `RF-METRIC-1.x`.
- [ ] **RF-QA-1.4** Every modified or new method is ≤20 LOC and complexity ≤10. Every extracted class is
      ≤200 LOC; any remaining framework adapter above 200 must have a documented follow-up item and no mixed
      domain behavior.
- [ ] **RF-QA-1.5** Public routes, response status/key/order, translation keys, exception messages, cache
      keys/TTLs, queue names, Composer commands, and package ordering are unchanged.
- [ ] **RF-QA-1.6** Marketplace checkout/entitlement evidence and install-attempt rows are identical; the
      append-only ledger retains one writer and no mutable execution state leaks across requests.
- [ ] **RF-QA-1.7** Public frontend output remains free of admin/editor/marketplace implementation details.
      None of the proposed slices requires a public rendering change.
- [ ] **RF-QA-1.8** Performance has not degraded: catalogue HTTP/query/iteration counts and installer
      command/job/step counts are equal or lower. Any benchmark uses the same fixtures and reports median plus
      sample count.
- [ ] **RF-QA-1.9** No new TODO/FIXME comments or compatibility shims are introduced silently. Deferred debt
      is listed below with an owner-level effort/risk estimate.
- [ ] **RF-QA-1.10** PHPStan, Pint, focused Pest, `composer test`, and `composer preflight` all pass from a
      non-concurrent checkout before the complete roadmap is declared done.
- [ ] **RF-QA-1.11** SOLID is applied pragmatically: each extracted module has one change reason, callers
      depend on typed inputs/results, extension interfaces remain substitutable, and no dependency inversion is
      added without a real second adapter.
- [ ] **RF-QA-1.12** Touched nested conditionals are flattened to at most two levels with guard clauses or
      composed methods; the four targeted duplicate groups are removed without creating a generic helper dump.
- [ ] **RF-QA-1.13** New code follows repository naming, strict typing, translations, Action/Data ownership,
      and PSR-12 conventions.

## Remaining Technical Debt and Follow-up

- [ ] **RF-DEBT-1.1 [Medium, large]** Re-run the threshold scan after this roadmap. Prioritise only classes
      where size aligns with high churn, responsibility mixing, or weak tests; do not pursue a repository-wide
      “under 200 lines” rewrite.
- [ ] **RF-DEBT-1.2 [Medium, medium]** Review declarative Filament builders over 200 LOC when those screens are
      next changed. Extract reusable schemas only where the same form behavior has at least two callers.
- [ ] **RF-DEBT-1.3 [Low, small per group]** Re-run exact clone detection and assess the remaining 65 groups.
      Keep local coercion methods where centralisation would increase coupling or erase domain names.
- [ ] **RF-DEBT-1.4 [Medium, medium]** Generate fresh Clover coverage after concurrent work settles. The
      2026-07-12 artifact must not be used to approve deletion or protocol splitting.
- [ ] **RF-DEBT-1.5 [Low, small]** Record the final installer and marketplace module ownership in the named
      contract docs and add Arch tests immediately after the dependencies become enforceable.

## Prevention and Lessons

- [ ] **RF-PREVENT-1.1** Keep controllers, Livewire components, Filament resources, and console commands as
      adapters: validate/authorise, invoke one Action, present one typed result.
- [ ] **RF-PREVENT-1.2** Introduce Data when an array crosses a request, UI, cache, job, or persistence seam,
      or when an Action exceeds three related parameters. Do not introduce Data for a private two-value tuple.
- [ ] **RF-PREVENT-1.3** Test Actions directly and retain one integration test at each framework adapter.
      The interface is the test surface; avoid source-string assertions.
- [ ] **RF-PREVENT-1.4** Apply the deletion test before extracting a module. Keep it only when deletion would
      spread policy/complexity back across multiple callers and reduce locality.
- [ ] **RF-PREVENT-1.5** Treat complexity thresholds as review triggers, not design goals. Prefer one deep,
      domain-named module over several pass-through classes, and add a pattern only for a concrete variation.
