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

## Acceptance matrix

These statuses describe the work in this branch, not release approval. The
legacy dashboard remains selected when `capell-admin.dashboard.work_first.enabled`
is false. The installation marker, companion providers and their independent
verification are tracked separately by the coordinating checkout.

| Requirement                                        | Evidence and outstanding work                                                                                                                                                                                                                                                                                                                                                                                                           |
| -------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Master fallback and per-widget switches            | Covered in `DefaultDashboardWidgetsTest.php`; disabled work and approvals provider calls are covered in `PersonalDraftsWidgetTest.php` and `ApprovalsWidgetTest.php`. The complete widget/job inventory still needs review.                                                                                                                                                                                                             |
| Personal layouts and scope                         | Order, hide/show, sizes, Save/Cancel, reset, separate site/All-sites storage and remembered scope have focused tests in `WorkFirstLayoutTest.php`, `WorkFirstScopeTest.php` and `AdminWorkspaceTest.php`. `WorkFirstStableRowsTest.php` covers update prompts and removal of unavailable rows. The chooser has grouped previews and separate ordering controls; it still needs shared-runner browser, keyboard and responsive evidence. |
| Personal work and approvals                        | Authorised own drafts and provider scope forwarding have focused tests; Publishing Studio owns the provider implementation and separate integration evidence. End-to-end assignment, deadline priority, review onboarding and permission coverage remain outstanding.                                                                                                                                                                   |
| Urgent summary, attention, publishing and activity | Separate work-first widgets are present; trace the coordinator’s tests and browser evidence before marking these complete. Optional content status and recently updated widgets are hidden by default and have site-scoped focused checks in `OptionalContentWidgetsTest.php`.                                                                                                                                                          |
| Analytics                                          | `BuildPageViewsActionTest.php` covers complete coverage, valid zero, disconnected, restricted role, failed fetch and stale cached figures. `VisitorsSnapshotTest.php` covers configured single-site visitor mapping and omits All-sites visitor totals. `BuildCompleteAnalyticsPeriodActionTest.php` covers complete source-local periods across DST. More multi-site, stale, source and browser cases remain.                          |
| Popular and rising                                 | `PageRankingsTest.php` covers populated and insufficient states, mapping, site selection and thresholds. The default source cannot supply page rankings; configured external providers need separate integration evidence.                                                                                                                                                                                                              |
| Accessibility and visual acceptance                | Shared-runner light/dark captures use clean source and zero browser/backend diagnostics. Native browser checks cover populated/empty states, 390px stacking, keyboard chooser/reorder/resize, Save/Cancel/restore and master-switch fallback with preserved preferences. Real optional approval-provider browser acceptance and some restricted/multilingual/failure states remain outstanding; diagnostic captures are not promoted.   |
| Full verification                                  | Affected dashboard, workspace and architecture checks pass 331 tests/1,432 assertions; installer path ordering and repair have 13 tests/37 assertions and fresh-install evidence. Configured screenshot validation and scoped PHPStan pass. The full gate previously reported unrelated Frontend failures; the final full gate, local matrix, coverage threshold and hosted checks remain required.                                     |

No optional integration is claimed merely because an unavailable state renders.

## Ranking capability and thresholds

The built-in daily rollups aggregate page views by site and language, and the
raw activity bucket retention defaults to one day. They cannot supply reliable
30-day page rankings. The work-first ranking provider therefore defaults to an
unavailable state. Enable `capell-admin.dashboard.work_first.analytics.rankings`
and set its `measure` and `source` labels to match the provider explicitly. Capable integrations must return
authorised managed-page counts for complete comparable periods, excluding utility and error pages.
Rising pages require at least 10 current observations, an absolute increase of
at least 5, and growth of at least 20%. A zero previous count is shown as a new
page only when the provider supplies separate page-creation evidence. The
provider and its labels, scope, freshness and completeness still need independent
integration and browser verification.

## Audience source mapping

The built-in page-view card uses complete UTC daily Capell activity rollups.
Visitors are omitted until `capell-admin.dashboard.work_first.analytics.visitors`
is explicitly enabled with `measure` and `source` labels matching an installed
`DashboardVisitorsProvider`. The visitor provider is called only for a selected,
authorised site; the All-sites view explains why visitor figures are omitted.
Providers own source-specific complete-period, permission and availability
semantics. The dashboard stores the last successful scoped snapshot without an expiry and
rechecks current access before displaying it as stale after a fetch failure; stale
ranking links are suppressed. Providers supply the actual source-local reporting
dates (inclusive start and exclusive end) with successful or stale figures.
Retention is subject to the configured cache store retaining permanent entries. This bridge has focused contract tests but no real provider
integration or browser acceptance yet.

## Configuration keys

`capell-admin.dashboard.work_first.enabled` is the master switch. Its package
configuration reads `CAPELL_WORK_FIRST_DASHBOARD`; existing installations stay
on the legacy dashboard unless explicitly enabled. Fresh-install enablement and
explicit opt-out handling has focused installer coverage. Canonical fresh-install verification also checks that separately registered user migrations precede the preference-column migration; a corrective migration preserves preferences on installations affected by the earlier ordering.

`capell-admin.dashboard.work_first.widgets` is a map of widget keys to booleans.
Setting a key to `false` overrides personal layouts and prevents that widget's
dedicated reads. The built-in keys are `urgent_summary`, `your_work`,
`approvals`, `attention`, `page_views`, `visitors`, `publishing_health`,
`popular_pages`, `rising_pages`, `team_activity`, `content_status` and
`recently_updated`. Unspecified keys default to enabled; the last two widgets
are offered in the chooser but hidden in a default layout. Hiding a widget
personally preserves its preferences and does not disable shared checks needed
by another widget.

`capell-admin.dashboard.work_first.widget_metadata` is keyed by registered widget
class and accepts optional `label`, `description`, `group` and `requirements`
strings. Supported groups are `work`, `content`, `performance`, `system` and
`extensions`; unknown widgets default to `extensions`. For example:

```php
'widget_metadata' => [
    ExampleWidget::class => [
        'label' => 'Example',
        'description' => 'A useful summary.',
        'group' => 'content',
        'requirements' => 'Requires the example provider.',
    ],
],
```

`capell-admin.dashboard.work_first.site_timezones` maps site UUIDs to IANA time
zone identifiers for publishing deadlines; the resolver uses the application
zone when a site has no mapping, and UTC for an invalid value. Built-in daily page-view rollups report
complete UTC days and label them as UTC. Optional source mappings are configured
under `capell-admin.dashboard.work_first.analytics.visitors` and
`capell-admin.dashboard.work_first.analytics.rankings`, each with `enabled`,
`measure` and `source`. Both mappings default off and must match the configured
provider's labels. Unsupported engagement and conversion measures are omitted.

Site and language choices, personal layouts and advisory decisions are stored
per user in the existing `admin_workspace_preferences` JSON column. Site-specific
layouts are shared across individual sites; the All-sites layout is separate.

## Installation recovery boundary

A normal new installation enables the dashboard once, and an explicit environment
or configuration opt-out is preserved. Saved installer inputs and caught step
failures preserve the verified origin. The pending origin is bound to the current
database configuration and its site/extension records; attaching a different
established database cannot turn an upgrade into a new installation.

If the installer process is terminated between a database write and its origin
checkpoint, the retry conservatively uses the existing dashboard. Set
`CAPELL_WORK_FIRST_DASHBOARD=true` to opt in after completing recovery. This
feature does not add a database identity table or claim recovery from every
uncatchable installation interruption.
