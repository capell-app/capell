# Work-first dashboard implementation inventory

This is an implementation checkpoint for the requirements in
[the approved specification](dashboard-work-first-spec.md), not a declaration
that the dashboard is complete.

## Existing capabilities

- `CapellDashboard` renders registered Filament widgets in regions and applies
  `AdminSettings` widget switches. `CapellAdmin` exposes provider registration.
- `MyWorkQueueDataProvider` is a neutral bridge; its default provider returns no
  work. The existing widget hides when empty and applies the dashboard date
  filter, neither of which satisfies the approved personal-work behaviour.
- `BuildDashboardAttentionAction` checks page setup via publication readiness and
  site access; it currently marks findings critical and does not implement the
  specified severity, advisory dismissal, or recovery history.
- `BuildDashboardAnalyticsSnapshotAction` reads Capell activity buckets and daily
  rollups with site-scoped queries. It mixes rolling and complete UTC windows,
  has no provider availability/failure contract, and cannot establish compatible
  cross-site unique visitors or reliable comparison coverage.
- Installer already patches panel dashboard and widget registrations; its admin
  install command also runs in contexts that must not be assumed to be fresh.
- Approval assignments and review history belong to Publishing Studio. The
  existing neutral work queue does not establish that bridge.

## Ordered slices and acceptance status

| Slice        | Required work                                                                                                 | Evidence / status                                                                       |
| ------------ | ------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Shell        | Durable fresh-install opt-in, master fallback, widget switches, authorised registry, saved layouts and scopes | Outstanding; installation lifecycle and Filament extension points require verification. |
| Work         | Personal work, approval bridge, evidence-based attention, publishing readiness and activity                   | Outstanding; optional approval provider needed.                                         |
| Performance  | Typed availability, complete periods, source mapping, coverage, comparisons and honest empty states           | Outstanding; existing snapshot is insufficient.                                         |
| Experience   | Widget designs, chooser, accessible layout editing and responsive states                                      | Outstanding; browser acceptance required.                                               |
| Verification | Behaviour regressions, permission isolation, focused and full gates, shared-runner screenshots                | Outstanding. No runtime or browser evidence yet.                                        |

The source tree and tests must be inspected in detail before each slice. Existing
providers and saved settings must remain compatible; no optional integration is
claimed solely because a placeholder renders.
