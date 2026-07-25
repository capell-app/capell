# Boot optimization candidate decisions

This record applies the admission rules from the Capell boot performance plan
to eager provider work. A candidate is retained only when its owning phase is
at least 10 ms or 3% of boot, focused behavior remains unchanged, and paired
p75 does not regress.

The benchmark measures full in-process Laravel application creation and
bootstrap. Child-process overhead remains diagnostic data and is not used to
admit an optimization.

## Profile evidence

Profiling on 24 July 2026 identified these first-party provider medians:

| Provider    | Register p50 |  Boot p50 | Decision                                           |
| ----------- | -----------: | --------: | -------------------------------------------------- |
| Core        |    13.746 ms |  1.025 ms | Investigate registration candidates                |
| Admin       |    28.153 ms |  6.838 ms | Move to the separate admin-activation phase        |
| Frontend    |     1.023 ms | 14.229 ms | Preserve required listener and component lifecycle |
| Marketplace |     0.086 ms |  0.993 ms | Below the admission threshold                      |

The host did not satisfy the baseline stability gate during this investigation:
load averages ranged from approximately 10 to 25 on 12 cores, the corrected
production median spread reached 6.62%, and IQR/median reached 18.77%.
Measurements below are therefore used only to reject regressions, never to
claim an improvement.

## Accepted closeout baseline

After the runtime lifecycle fixes were committed in `a98aee15d`, the host load
settled sufficiently to establish the required optimized-cache baseline. Each
profile was measured in three independent runs of 25 samples after five
warmups:

| Profile    | Run p50 medians           | Median spread | Worst IQR / median | Fingerprint                                                        |
| ---------- | ------------------------- | ------------: | -----------------: | ------------------------------------------------------------------ |
| Production | 108.33, 107.39, 107.57 ms |         0.87% |              2.90% | `5fe3451cbfbeb16f022d9e38af5da5d3a4778563f9f26cb49ecffee170378ae9` |
| Public     | 48.93, 47.94, 47.72 ms    |         2.52% |              5.97% | `f1b774cb70141e8eea9c1d6836eb69ab546e7bc3ee07c0da4dddf7f55ac77881` |
| Admin      | 108.25, 108.34, 107.37 ms |         0.90% |              6.05% | `a08050cf80923380cccd58e93671fc9a6f913ef2b285d3ef3cf6700a11be37d7` |

All three profiles satisfy the baseline gate of no more than 3% median spread
and no more than 10% IQR/median. Tukey outliers remain in the retained raw
samples and were not removed from the reported statistics.

## Rejected: lazy Core built-in registries

The candidate moved built-in renderable and linkable-content definitions into
their singleton factories. Focused link, renderable, and boot-contract tests
passed, but all three optimized production pairs regressed:

| Pair | p50 delta |  p75 delta |
| ---: | --------: | ---------: |
|    1 | +0.788 ms |  +8.995 ms |
|    2 | +6.798 ms | +10.608 ms |
|    3 | +9.011 ms | +14.837 ms |

The candidate was reverted. It is not part of a delivery branch.

## Preserved lifecycle work

- Core settings configuration must be ready before the Spatie settings
  provider consumes it. Dependency default configuration is loaded from the
  filesystem only when configuration is uncached; cached production boot keeps
  that fallback disabled.
- Core event-sourcing projectors, reactors, aggregates, and rollback validators
  must be registered before Spatie builds the projectionist.
- Frontend component contributors are already materialized through lazy
  container bindings.
- Frontend cache invalidation observers, the documented bounded wildcard model
  listeners, and route-reservation contributions must remain available during
  normal provider registration and boot.
- Marketplace provider time is below the candidate threshold.

No further independently deferable Core, Frontend, or Marketplace candidate
was retained. Admin surface materialization and bridge boot are handled by the
separate idempotent admin runtime activation change.

## Safety outcome

This phase changes no runtime code. It preserves contribution ordering,
anonymous rendering and cache contracts, wildcard listeners, routes,
middleware, and extension declarations. No new database or filesystem work is
introduced at boot.

---

# CAP-0029 (2026-07-25)

## Measurement environment — not comparable to the CAP-0028 baseline

CAP-0028's figures come from the local Testbench boot benchmark. CAP-0029 was
measured against a **real Laravel 13.22 install of the public Capell packages**
(12 `capell-app/*` packages, MariaDB 10.5, Redis, demo content) on a shared
2-core host, with the benchmark confined to one core at `nice 19`.

Absolute milliseconds therefore do **not** transfer between the two documents;
proportions do. Observed run-to-run spread on this host is roughly **45%**,
which means it cannot resolve differences below about 50 ms. Every verdict
below is stated against that floor.

## Profile evidence

Bootstrapper split (`$kernel->bootstrap()` is ~96% of boot):

| Bootstrapper             |     ms |     % |
| ------------------------ | -----: | ----: |
| BootProviders            |  528.9 | 68.4% |
| RegisterProviders        |  222.6 | 28.8% |
| RegisterFacades          |   10.7 |  1.4% |
| HandleExceptions         |    5.5 |  0.7% |
| LoadConfiguration        |    4.8 |  0.6% |
| LoadEnvironmentVariables |    0.3 |  0.0% |

89 providers total: 14 first-party, 54 third-party, 21 Laravel.

Slowest boot contributors: Filament 221.6 ms (36.8%), Livewire 68.5 ms,
FilamentTinyEditor 66.2 ms, HtmlCache 55.5 ms, LayoutBuilder 32.2 ms,
BlockLibrary 29.2 ms. Slowest register contributors: CapellServiceProvider
92.5 ms (39.7%), PowerJoins 27.2 ms, AdminServiceProvider 17.9 ms.

End-to-end cached public homepage through nginx + PHP-FPM: n=20, min 56.0 ms,
**p50 65.3 ms**, p95 78.8 ms. Render alone once booted is ~1.7 ms, so boot
dominates a public request almost entirely.

**Config caching is not the lever.** LoadConfiguration is 0.6% of boot, which
matches the measured result that caching config/routes/events/views moved CLI
boot only 433 ms -> ~404 ms, inside noise.

## Rejected: batch manifest registration and de-duplicated registry write

`ManagesPackages::registerManifestPackages()` collapsing per-package memo wipes
into one, plus removing the duplicate `CapellPackageRegistry` write already
performed by `fill()`. 12 interleaved pairs: baseline median 86.0 ms, candidate
median 86.3 ms. Reverted; not part of a delivery branch.

## Rejected: process-constant probe memoisation

Static memoisation of `isLivewireV3()`, `isDiscoveringPackages()` and
`resolvePackagePath()` on `AbstractPackageServiceProvider`. 10 interleaved
pairs: baseline median 104.3 ms, candidate median 97.7 ms, but paired median
delta +0.7 ms. `resolvePackagePath()` is called once per provider class per
boot, so there are no repeat calls to memoise. Reverted.

## Rejected: Filament component caching as a measured win

`filament:cache-components` showed no improvement here: baseline median
229.8 ms vs cached median 279.8 ms, with fully overlapping ranges. The test
install has a nearly empty panel, so there is almost nothing to discover — this
result does **not** generalise to a production panel. It is still applied at
deploy time as documented Filament hardening, but no measured gain is claimed.

## Accepted: targeted reflection in extension page navigation suppression

`CapellAdminManager::suppressExtensionPageNativeNavigation()` built a full
`ReflectionClass` and walked `hasProperty()`/`getProperty()`/`isStatic()` to
reach one protected static property, forcing the whole Filament `Page` ancestor
property table to resolve. Replaced with a single targeted `ReflectionProperty`
plus a per-manager memo so a page registered twice does not pay twice.

| Pair | baseline |  candidate |       delta |
| ---: | -------: | ---------: | ----------: |
|    1 |  60.4 ms |    58.6 ms |     -1.8 ms |
|    2 |  88.5 ms |    42.2 ms |    -46.3 ms |
|    3 |  54.8 ms |    42.7 ms |    -12.1 ms |
|    4 |  56.3 ms |    55.1 ms |     -1.2 ms |
|    5 |  42.3 ms |    44.8 ms |     +2.5 ms |

Mean 60.5 ms -> 48.7 ms (-19.5%), 4 of 5 pairs improved. Reflection remains
required because the property is `protected`; the behaviour, including the
silent no-op when the property is absent or non-static, is unchanged and pinned
by `ExtensionsPageTest`.

## Findings recorded rather than fixed here

- **Provider boot performs database work.** A `Schema::hasTable()` probe runs
  during boot via `RuntimeSchemaState`. It is already `scoped()` and memoised
  per request, and a process-level cache was rejected as unsafe (test isolation
  and staleness across an Octane worker). `config/deploy.php` in capell-app
  documents forcing an in-memory connection for its control plane because of
  this behaviour.
- **Deploys shipped with no warm caches.** The build phase deleted
  `bootstrap/cache/filament` and `bootstrap/cache/capell` and never rebuilt
  them. Fixed in capell-app's `config/deploy.php`.
- **CLI has no opcache.** `opcache.enable_cli=0`, so every artisan invocation
  recompiles all sources. Enabling it with a file cache measured CLI boot at
  215-253 ms versus ~404 ms.
- **Admin-only providers boot on public requests.** Filament, TinyEditor,
  Shield and Curator together are ~320 instrumented ms that a public page never
  needs. Deferring them is architecturally sound but was assessed as the last
  lever to pull, worth an estimated 25-35 ms real p50, and is not attempted
  here.

## Verification boundary

Measured on one shared 2-core host; absolute values are host-specific. No
throughput or concurrency testing was performed, deliberately, because the host
also serves unrelated production sites. `packages/core/tests/Arch/PackagesTest.php`
could not be executed in this environment and its state is unverified.
