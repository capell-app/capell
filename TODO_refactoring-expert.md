# TODO — Refactoring Expert Review: Capell Provider/Manager Simplification

> Companion to the execution plan at `~/.claude/plans/continue-the-repository-wide-simplificat-recursive-trinket.md`.
> Every task below is trackable; IDs are stable. Code blocks are **target-state sketches** — validate line
> numbers and signatures against the current tree before applying (a concurrent session commits to
> `hotfix/tweaks` continuously; freshness-check every target with `git log --oneline -3 -- <file>`).

---

## Part A — Expert review of the plan (completeness verdict)

### A.1 Traceability: audit findings → plan coverage

- [x] **RF-REVIEW-1.1** Frontend provider top-10 findings — all 10 covered (plan Phases 0.2, 2.1–2.4).
- [x] **RF-REVIEW-1.2** Admin/core provider top-10 findings — all covered (Phases 0, 1.1–1.3, 3.2–3.3), including honorable mentions (dir-scan, Blaze, morph guard, double binding, recipients query, dormant Macroable, inline `Builder::macro`, duplicated `class_exists`).
- [x] **RF-REVIEW-1.3** Repo-wide sweep top-12 — items 1–6, 9, 11, 12 covered. **Gap found and fixed**: items 7 (`UpgradePage` ladder), 8 (`ContentBuilder` ladder), 10 (`@php`-heavy blades) were missing from Phase 6 — now added to the plan.
- [x] **RF-REVIEW-1.4** Provider-matrix audit (8 divergences) — all 8 covered (Phases 0.1–0.5, 2.1, 4.5, 1.3).
- [x] **RF-REVIEW-1.5** Registry-landscape audit (8 opportunities) — all 8 covered (Phases 4.1–4.6, 5.1–5.2).

### A.2 Review verdicts (strengths kept, weaknesses to address)

- [ ] **RF-REVIEW-2.1 Add measurable success targets** (the plan states verification but not numeric goals). Adopt the targets table in §B below; record before/after in the ledger per phase. *Severity: Medium — without numbers, "improved" is unfalsifiable.*
- [ ] **RF-REVIEW-2.2 Add an explicit rollback rule**: every slice = one revertable commit; if a slice's covering suite fails and the fix isn't obvious within the slice, `git revert` the slice rather than stacking fixes. *Severity: Low.*
- [ ] **RF-REVIEW-2.3 Phase 0 conflict-surface warning**: Phase 0 touches all five providers at once — highest merge-conflict surface with the concurrent session. Slice 0.2 (base-method lift) per-method, not per-provider: one commit lifts `registerAboutInfo` across all 3 copies (tiny), next lifts `registerPackageMetadata` across 5, etc. Small cross-file commits conflict less than big per-file ones here. *Severity: Medium.*
- [ ] **RF-REVIEW-2.4 Consistency micro-items missing from Phase 0** (from the matrix audit): only frontend provider is `final`; only installer/marketplace set `static $type` explicitly; `registerBlazeComponents` vs `registerBladeComponents` naming collision (Blaze ≠ Blade) invites mistakes — rename one during the 0.2 lift. Add as a Phase 0.6 sweep. *Severity: Low.*
- [ ] **RF-REVIEW-2.5 No complexity tooling exists** in the repo (PHPStan ≠ complexity metrics). Either accept lines/branch-count as the metric (pragmatic, zero new deps) or add a dev-only metrics tool in Phase 7 — decision for the maintainer; do NOT block phases on it.

### A.3 Suggested new features (owner-level; enabled by this refactor — optional, separate approval)

- [ ] **RF-FEAT-3.1 `capell:surfaces` introspection command** — lists every registry (post-4.1 they share a base, so enumeration is trivial), its bound lifetime, Octane classification, and registered entries. Turns the invisible registry landscape into a debuggable surface for extension developers; near-free once `AbstractKeyedRegistry` exists.
- [ ] **RF-FEAT-3.2 Doctor checks** (repo has a doctor pipeline — `BuildDoctorReportAction`): add checks for (a) manifest cache present (else warn "boot runs discovery"), (b) any singleton failing the Octane classification, (c) provider-conventions Arch suite green. Ships the Phase 7 guards to end users, not just CI.
- [ ] **RF-FEAT-3.3 `capell:boot-profile` command** — boots the app N times, reports median wall time + counts of listeners/observers/bindings registered. Makes the Phase 1–2 perf wins visible and regressions observable in production support.
- [ ] **RF-FEAT-3.4 Extension-developer changelog artifact** — Phase 4/5 surface changes auto-summarized from the extension-surface-catalog diff (the check already exists) into a release-notes fragment. Third-party authors get told what moved instead of discovering it.

---

## Part B — Context: baselines, smells, priorities

**Priorities (from maintainer):** (1) remove overcomplication/"AI slop" and pattern proliferation, (2) per-request performance, (3) maintainability of providers/managers. Readability over micro-perf except on the boot path.

### B.1 Metric baselines (verified on `hotfix/tweaks`, 2026-07-18)

| Target | Baseline | Target after | Notes |
|---|---|---|---|
| `FrontendServiceProvider` | 924 lines (178 imports) | ≤ 700 | bindings already grouped (`ca667312d`) — do not re-touch |
| `AdminServiceProvider` | 1,062 | ≤ 700 | |
| `CapellServiceProvider` | 906 | ≤ 650 | |
| `CapellAdminManager` | 756 (+7 traits) | ≤ ~350 | 4 inline stores + ~180 lines widget-sort out |
| `CapellCoreManager` facade weight | 77 + 15 traits ≈ 2,596 | HasPackages/HasCache/HasModelInterceptors re-homed | facade signatures FROZEN (233 consumer files) |
| Keyed-registry implementations | ~35 near-identical | 1 base + thin subclasses | verbs canonicalized |
| Installed-gated boot mechanisms | 4 | 1 | admin's shape is canon |
| Optional-integration idioms | 7 | 2 (tags + bridge registry) | |
| Settings write paths | 5 | 2 (`surface()` + bridge registrar) | |
| "Run when X resolves" idioms | 3 | 1 (`callAfterResolving`) | |
| `registerAboutInfo` copies | 3 (byte-identical) | 1 (base) | |
| `registerPackageMetadata` copies | 5 (divergent) | 1 (base) | |
| Per-request reflection+`require` | 2 sites (core :323, :742) | 0 | |
| Per-request FS work | ≥5 sites (theme CSS read, paginate translations, Blaze ×2, admin dir-scan) | 0 on web requests | |
| Wildcard `eloquent.*` listeners | 3 | 0 or documented-bounded | |
| `getPackages()` sorts per admin request | ≥3 | 1 (memoized) | |
| Octane-reset participants | 3 of ~55 stateful classes | 100% of request-mutable classified | |

### B.2 Code smells detected (severity)

| Smell | Where | Severity |
|---|---|---|
| Potential cross-request state leak (Octane) | ~30 stateful singletons unclassified | **Critical** (multi-site data-bleed risk) |
| Per-request reflection/FS/config work | core :314–370, :407–449, :740–786; frontend :734–805, :517, :595; admin :842, :851 | **High** |
| God class + Feature Envy | `CapellCoreManager` traits; `HasPackages` (692) shadowing `CapellPackageRegistry` (124) | **High** |
| Divergent duplicate abstractions | 35 keyed registries; 4 boot idioms; 7 integration idioms | **High** (the maintainer's core complaint) |
| Duplicate code (providers) | aboutInfo ×3, packageMetadata ×5, blaze ×2, livewire ×3, schedule ×3 shapes | **High** |
| Dead/unreachable code | 19-arm match `default` (frontend :651), `environment()` guard (core :464), Livewire v2 branch, dormant `Macroable`, `ThemeRegistry::reset()` unwired, `FileViewFinder` self-rebind | **Medium** |
| Stringly-typed indirection | `implode('\\',[...])` classname + `is_callable` (admin :351–431) | **Medium** |
| Inappropriate intimacy / triple ownership | AdminBridge manager+registry+registrar; `AdminBridgeRegistry` bound to manager's private field | **Medium** |
| N+1-ish query smell | `defaultPackageOperationRecipients` loads all users, filters in PHP (admin :461–481) | **Medium** |
| Long parameter-free repetition | overview stats ×4, interceptors ×5, dashboard widgets ×18, settings groups ×3 | **Medium** |
| Internal defensive ladders | `ResolveFrontendResourcePlanAction` (24), `UpgradePage` (31), `ContentBuilder` (30) | **Low** |
| Collection churn / `@php` blades | ~9 `collect()->all()` sites; nested array reshapes; 3 blades | **Low** |

**Explicit non-goals (correct as-is; do not "fix"):** external-input validators (`MarketplaceClient`, `ManifestValidator`, `CapellManifestData` ladders), persisted `#[Computed]` Filament widgets, `ManifestLoader` discovery caching, `Contracts/*` single-implementation SDK interfaces.

---

## Part C — Refactoring plan (RF-PLAN, maps 1:1 to execution-plan phases)

- [ ] **RF-PLAN-0.1 [Template Method / Pull Up]** — one installed-gated boot idiom on `AbstractPackageServiceProvider`.
  **Target:** all 5 providers. **Reason:** 4 mechanisms for one concern; frontend leaks `registerLivewireComponents` outside its gate. **Risk:** Medium (boot-order sensitivity) — mitigate: provider tests + per-provider slices. **Priority: 1.**
- [ ] **RF-PLAN-0.2 [Pull Up Method]** — lift `registerAboutInfo` / `registerPackageMetadata` / `registerBlazeComponents` / Livewire-registration helper into the base; delete the copies; drop first-party `class_exists` guards while lifting.
  **Target:** base + 5 providers. **Reason:** 3–5× duplication with drift. **Risk:** Low. **Priority: 1.**
- [ ] **RF-PLAN-0.3 [Canonical idiom]** — `callAfterResolving` everywhere; delete hand-rolled `afterResolving`+`resolved()` catch-ups (core :807–819, admin :371–380). **Risk:** Low. **Priority: 2.**
- [ ] **RF-PLAN-0.4/0.5** — schedule-registration canon (admin's shape); decide `surface()` (adopt in providers or delete). **Risk:** Low. **Priority: 2.**
- [ ] **RF-PLAN-1.1 [Memoization + Compose Method]** — `HasPackages::getPackages()` single-pass + instance memo; memo `getUninstalledExtensionNames()`; invalidate on mutation. **Risk:** Low-Medium (staleness) — mitigate: invalidation test. **Priority: 1.**
- [ ] **RF-PLAN-1.2 [Move normalization to constructor / typed accessors]** — `CapellManifestData` accessors replace 14 reshape ternaries in `registerManifestPackage` (:147–167). **Risk:** Low. **Priority: 2.**
- [ ] **RF-PLAN-1.3 [Extract Class ×3 + dead-code kills]** — `EventSourcingBootstrapper`, `PackageRegistryBootstrapper`, `SettingsBootstrapper`; kill dead guards; fix `CapellCoreManager` double binding (see RF-ITEM-G). **Risk:** Medium (boot path) — mitigate: `PackageCacheCommandTest`, `CorePackageTest`. **Priority: 1.**
- [ ] **RF-PLAN-2.1 [Replace Conditional with Lookup]** — `scheduleSiteCheck` 19+19+dead-default → data map (RF-ITEM-A). Characterization test first. **Risk:** Low. **Priority: 2.**
- [ ] **RF-PLAN-2.2 [Extract Class / Move Method]** — theme-runtime HeadClose closure → hook class via `FrontendHookRegistrar` (RF-ITEM-B). Characterization test first. **Risk:** Medium (render output) — pin output. **Priority: 2.**
- [ ] **RF-PLAN-2.3 [Extract Class + bounded observers]** — component registrar dedup; delete Livewire v2 branch (verify v3+); event subscriber; **evaluate** scoping 3 wildcard `eloquent.*` listeners (RF-ITEM-L — behavior-adjacent; stop-and-surface if wildcard is load-bearing). **Risk:** Medium. **Priority: 2.**
- [ ] **RF-PLAN-2.4 [Inline / delete]** — `FileViewFinder` self-rebind, one-line wrapper, `is_string` guard-loop helper, gate paginate/Blaze FS work. **Risk:** Low. **Priority: 3.**
- [ ] **RF-PLAN-3.1 [Characterization tests]** — overview stats, dashboard widgets, model interceptors, settings schemas (no registration tests exist). **Risk:** —. **Priority: 1 (blocks 3.2).**
- [ ] **RF-PLAN-3.2 [Replace repetition with data]** — the four admin registration walls + report catalog + 3-loop collapse (~250 → ~90 lines). **Risk:** Low after 3.1. **Priority: 2.**
- [ ] **RF-PLAN-3.3 [Kills]** — implode-classname reservation → plain class-string + `callAfterResolving`; recipients → DB-scoped query resolver (RF-ITEM-H); ActAsOwner subscriber; dormant `Macroable`; inline `Builder::macro` → macros class. **Risk:** Low-Medium. **Priority: 2.**
- [ ] **RF-PLAN-4.1 [Extract Superclass]** — `AbstractKeyedRegistry` + migrate ~35 Cluster-A registries per package (RF-ITEM-D). **Risk:** Medium (churn volume; concurrent session) — per-package slices, package suite green each. **Priority: 2.**
- [ ] **RF-PLAN-4.2–4.6 [Consolidations]** — tag-wrapper collapse; one settings write path; bridge-trio dedup; integration idioms 7→2; lifetime/Octane rule fixes (`ThemeRegistry` dead reset, scoped/singleton outliers, bind the `new`'d registries). **Risk:** Medium. **Priority: 2–3.**
- [ ] **RF-PLAN-4.7 [Octane leak sweep]** — classify all stateful singletons; leak-test harness; fix request-mutable ones. **Risk:** Medium; **Severity addressed: Critical.** **Priority: 1.**
- [ ] **RF-PLAN-4.8 [Delete speculative surface]** — zero-writer registries keep-or-delete (pre-1.0 window). **Risk:** Low (unreleased). **Priority: 3.**
- [ ] **RF-PLAN-5.1 [Extract Class ×4]** — CapellAdminManager inline stores → registries on the 4.1 base; facade delegates (RF-ITEM-J). **Risk:** Medium. **Priority: 2.**
- [ ] **RF-PLAN-5.2 [Move Method / slim god class]** — `HasPackages` → `CapellPackageRegistry` ownership; `HasCache`/`HasModelInterceptors` → bound services; facade signatures frozen (RF-ITEM-K). **Risk:** High (233 consumers) — mitigate: PHPStan + full suite + per-trait slices. **Priority: 2 (after 1.1/1.2).**
- [ ] **RF-PLAN-6.x [Clarity tail]** — resource-plan action ladders, marketplace reshapes, `SiteLoader` guards, `UpgradePage`/`ContentBuilder` ladders (characterize `ContentBuilder` first), `@php` blades, `collect()->all()` sweep. **Risk:** Low. **Priority: 4–5.**
- [ ] **RF-PLAN-7.1–7.5 [Guardrails]** — pattern-ratchet Arch tests; boot-perf pins + benchmark; conventions doc; registry test dedup; CI wiring for the offline Playwright spec. **Risk:** Low. **Priority: 2 (each lands right after its canon).**

---

## Part D — Key refactoring items (before/after)

- [ ] **RF-ITEM-A `scheduleSiteCheck` → data map**
  **Pattern:** Replace Conditional with Lookup Table. **Before:** 19-entry whitelist array + `in_array` + 19-arm `match` re-listing the same strings + unreachable `default` (47 lines, runs every web request). **After:** one `array<string, string>` map, one guarded dynamic call, `runningInConsole()` gate (~12 lines). **Metrics:** 47→~12 lines; branch count 20→2; web-request cost → 0.

- [ ] **RF-ITEM-B Theme-runtime render hook → class**
  **Pattern:** Extract Class + route through existing seam. **Before:** 66-line inline closure in the provider doing theme resolution, token merging, `is_file`/`file_get_contents`, CSS emit — the only inline hook registration in the repo, bypassing `FrontendHookRegistrar`. **After:** `Support/Themes/ThemeRuntimeCssHook` registered via `FrontendHookRegistrar::contribute()`; provider one-liner. **Metrics:** provider −65 lines; hook independently testable; FS read memoizable.

- [ ] **RF-ITEM-C `getPackages()` memoization**
  **Pattern:** Memoization (repo's own instance-cache idiom). **Before:** `collect($this->packages)->sortBy(...)->values()->all()` then `collect($sortedArray)->keyBy(...)` on every call; ≥3 calls per admin request. **After:** single chain + `$this->packagesCache[$cacheKey] ??=`; cleared alongside the trait's existing caches. **Metrics:** O(n log n)×k per request → ×1; zero API change.

- [ ] **RF-ITEM-D `AbstractKeyedRegistry`**
  **Pattern:** Extract Superclass. **Before:** ~35 classes each re-implement `private array $items` + `register()` + reader + dump with divergent verbs (`get`/`provider`/`definition`/`classes`/`getSchema`; `all`/`definitions`/`getReports`). **After:** one base with `register/get/has/all/clear`; subclasses keep only domain logic (sorting, grouping); call sites renamed to canon verbs (unreleased — no aliases). **Metrics:** ~35 × ~40 lines of duplicate mechanics → 1 base + thin subclasses; one behavior test suite replaces ~35 copy-paste suites.

- [ ] **RF-ITEM-E `registerAboutInfo` ×3 → base**
  **Pattern:** Pull Up Method + remove impossible-state guards. **Before:** byte-identical method in 3 providers, each guarding `class_exists(AboutCommand)` and `class_exists(InstalledVersions)` (always true). **After:** one base method using `static::$name`/`static::$packageName`; guards reduced to `runningInConsole()`. **Metrics:** 3 copies → 1; −2 dead guards ×3.

- [ ] **RF-ITEM-G Core manager double binding**
  **Pattern:** Replace duplicate binding with alias (frontend's idiom is canon). **Before:** `scoped('capell-admin', fn() => new CapellCoreManager)` **and** `singleton(CapellCoreManager::class, fn() => new CapellCoreManager)` — two instances, two lifetimes, misleading string key. **After:** one `singleton(CapellCoreManager::class, ...)` + `alias('capell-admin', CapellCoreManager::class)` (verify `'capell-admin'` consumers first — may be public API). **Metrics:** 1 instance; state consistency between access paths.

- [ ] **RF-ITEM-H Package-operation recipients**
  **Pattern:** Extract Class + push predicate into the query. **Before:** `$userModel::query()->get()->filter(fn ($user) => method_exists(...) && ...)` — full-table hydration + per-row reflection. **After:** dedicated resolver; DB-scoped query where the model supports it, preserving the optional-method tolerance for models that don't. **Metrics:** O(all users) hydration → bounded query; provider −20 lines. *Behavior-adjacent: keep result set identical; test with both user-model shapes.*

- [ ] **RF-ITEM-I Manifest reshape ladders → typed accessors**
  **Pattern:** Move normalization to construction. **Before:** 14 `is_string($manifest->commands['install'] ?? null) ? ... : null`-style reshapes per package per boot. **After:** `CapellManifestData::installCommand(): ?string` etc., normalized once. **Metrics:** per-boot reshapes → 0; `registerManifestPackage` −~14 branches.

- [ ] **RF-ITEM-J CapellAdminManager inline stores → registries**
  **Pattern:** Extract Class (finish the half-done migration). **Before:** four keyed-array stores (dashboard widgets + ~180 lines of sort helpers, marketing actions, user-menu items, overview stats) inline, beside three already-extracted registries in the same constructor. **After:** four `AbstractKeyedRegistry` subclasses; manager facade-delegates. **Metrics:** 756 → ~350 lines; zero facade signature changes.

- [ ] **RF-ITEM-K `HasPackages` → `CapellPackageRegistry`**
  **Pattern:** Move Method (fix Feature Envy: the 692-line trait wraps the 124-line registry that already exists). **Before:** trait owns storage + logic; registry is `new`'d, unbound, shadowed. **After:** container-bound registry owns storage/logic; trait methods delegate (facade frozen). **Metrics:** god-manager −~550 effective lines; registry becomes swappable/testable.

- [ ] **RF-ITEM-L Wildcard eloquent listeners → bounded**
  **Pattern:** Replace global hook with targeted observers. **Before:** 3 `eloquent.{created,updated,deleted}: *` listeners fire `ErrorPageModelInvalidationObserver` for every model event of every request. **After (if verified safe):** observer attached to the bounded model set that can affect error pages. **Metrics:** per-model-event global dispatch → targeted. *Stop-and-surface if arbitrary page-type models make the wildcard load-bearing.*

---

## Part E — Proposed code changes (sketches; validate before applying)

### E.1 Base provider additions (`packages/core/src/Support/Packages/AbstractPackageServiceProvider.php`)

```php
protected function bootWhenInstalled(): void
{
    $this->app->booted(function (): void {
        if ($this->isDiscoveringPackages() || ! $this->isPackageInstalled()) {
            return;
        }

        $this->bootInstalledPackage();
    });
}

// Providers override; canonical signature (today: admin `private self`, frontend `public void`).
protected function bootInstalledPackage(): void {}

protected function registerAboutInfo(): void
{
    if (! $this->app->runningInConsole()) {
        return;
    }

    AboutCommand::add('Capell', [
        static::getName() => InstalledVersions::getPrettyVersion(static::$packageName),
    ]);
}
```

### E.2 `scheduleSiteCheck` replacement (frontend provider, currently L614–660)

```php
private const SCHEDULE_FREQUENCIES = [
    'everyMinute' => 'everyMinute', 'everyFiveMinutes' => 'everyFiveMinutes',
    // ... one entry per supported name; single source of truth
];

private function scheduleSiteCheck(): void
{
    if (! $this->app->runningInConsole()) {
        return;
    }

    $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
        $frequency = self::SCHEDULE_FREQUENCIES[config('capell-frontend.schedule_page_cleaner')] ?? null;
        if ($frequency === null) {
            return; // invalid config: silently skip or Log::warning once — keep current logging behavior
        }
        $schedule->command(/* current command */)->{$frequency}();
    });
}
```

### E.3 `getPackages()` memo (`packages/core/src/Concerns/HasPackages.php` L197–219)

```php
/** @var array<string, Collection<string, PackageData>> */
private array $packagesCache = [];

public function getPackages(bool $sortByDependencies = false /* keep current signature */): Collection
{
    $cacheKey = $sortByDependencies ? 'sorted' : 'unsorted';

    return $this->packagesCache[$cacheKey] ??= collect($this->packages)
        ->when($sortByDependencies, fn (Collection $packages) => $packages->sortBy($this->getSort(...))->values())
        ->keyBy(fn (PackageData $package): string => $package->name);
}
// clearPackages()/clearExtensionCache()/registerManifestPackage(): $this->packagesCache = [];
```

### E.4 Core manager binding fix (`CapellServiceProvider` L500/L510)

```diff
- $this->app->scoped('capell-admin', fn (): CapellCoreManager => new CapellCoreManager);
  ...
  $this->app->singleton(CapellCoreManager::class, fn (): CapellCoreManager => new CapellCoreManager);
+ $this->app->alias(CapellCoreManager::class, 'capell-admin');
```

### E.5 `AbstractKeyedRegistry` (new, `packages/core/src/Support/Registries/`)

```php
/** @template TItem */
abstract class AbstractKeyedRegistry
{
    /** @var array<string, TItem> */
    private array $items = [];

    /** @param TItem $item */
    public function register(string $key, mixed $item): void { $this->items[$key] = $item; }
    /** @return TItem|null */
    public function get(string $key): mixed { return $this->items[$key] ?? null; }
    public function has(string $key): bool { return array_key_exists($key, $this->items); }
    /** @return array<string, TItem> */
    public function all(): array { return $this->items; }
    public function clear(): void { $this->items = []; }
}
// Subclasses keep domain-specific readers (sorted dumps, grouping) and typed register() overloads
// that extract the key from the DTO and forward to parent::register().
```

---

## Part F — Commands

```bash
# Freshness check before every task (concurrent session!)
git status --short && git log --oneline -3 -- <target files>

# Per slice
vendor/bin/pest <covering test paths> --configuration=phpunit.xml
composer analyze                     # PHPStan level 8, reportUnmatchedIgnoredErrors

# Phase end
composer test                        # clear + prepare + full parallel pest
composer preflight                   # phpstan + rector-dry + pint + prettier + eslint + pest

# JS/browser-touching slices (Phase 7.5)
npm run test:installer-browser

# Docs-touching slices
composer check:docs-links && composer check:docs-orphans
composer check:extension-surfaces && composer check:stable-extension-api

# Boot benchmark (Phase 7.2; scratchpad script, numbers into ledger + commit messages)
php <scratchpad>/boot-bench.php   # boots testbench app N times, prints median ms
```

---

## Part G — Quality assurance checklist (verify before declaring any phase done)

- [ ] **RF-QA-1** All existing tests pass **without modifying assertions** (exceptions: tests deleted with the dead code they pinned — named in the commit).
- [ ] **RF-QA-2** Each slice is one revertable commit; no behavior change mixed into structural slices (behavior-adjacent items — RF-ITEM-H/L, sync-branch backlog — are their OWN commits with the equivalence argument in the message).
- [ ] **RF-QA-3** Before/after metrics recorded in the ledger against the §B.1 table.
- [ ] **RF-QA-4** Facade signatures (`CapellCore`, `CapellAdmin`) unchanged — PHPStan across 233/98 consumer files is the proof.
- [ ] **RF-QA-5** Binding keys, container tags (`Resettable`!), and registration phases identical — `FrontendPackageTest`/`CorePackageTest`/`PolicyRegistrationTest` pass unmodified.
- [ ] **RF-QA-6** No new pattern variants introduced: every new class uses the canon (keyed-registry base, `callAfterResolving`, tags/bridge integration) — enforced by the Phase 7 Arch tests once landed.
- [ ] **RF-QA-7** Perf claims quantified (ops removed, with file:line) — no unmeasured "faster".
- [ ] **RF-QA-8** Remaining debt logged: anything skipped due to concurrent-session collision goes to the ledger with a revisit note.
