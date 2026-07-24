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
