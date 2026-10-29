# Work-first dashboard implementation inventory

This inventory maps the requirements in [the approved specification](dashboard-work-first-spec.md)
to the implementation and its verification routes. Current gate results and
outstanding checks are recorded in [Foundation PR #964](https://github.com/capell-app/capell/pull/964)
and [Publishing Studio PR #1885](https://github.com/capell-app/capell-packages/pull/1885).

## Implementation boundaries

- Admin owns the dashboard shell, widget designs, actor preferences, attention
  and analytics contracts. Optional workflow integration uses neutral contracts.
- Publishing Studio supplies assigned work, approvals, workspace readiness,
  meaningful activity and verified publication recovery. Registration checks
  the available Admin capabilities and preserves the older provider fallback.
- Installer enables the new dashboard for a verified fresh installation and
  preserves explicit opt-outs and existing-installation configuration.
- The existing Capell dashboard remains available through the master switch.

## Acceptance matrix

These statuses describe the work in this branch, not release approval. The
legacy dashboard remains selected when `capell-admin.dashboard.work_first.enabled`
is false. The installation marker, companion providers and their independent
verification are tracked separately by the coordinating checkout.

| Requirement                                        | Evidence and outstanding work                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Master fallback and per-widget switches            | Focused configuration and provider-call tests cover the master fallback, short/class widget switches, mounted reads and preserved preferences. Native browser checks confirm master off/on behaviour.                                                                                                                                                                                                                                                                                                                    |
| Personal layouts and scope                         | Order, hide/show, sizes, Save/Cancel, reset, separate site/All-sites storage and remembered scope have focused tests in `WorkFirstLayoutTest.php`, `WorkFirstScopeTest.php` and `AdminWorkspaceTest.php`. `WorkFirstStableRowsTest.php` covers update prompts and removal of unavailable rows. The chooser has grouped previews and keyboard move/resize controls. Native browser checks cover Save/Cancel/restore, keyboard interaction and 390px stacking; shared-runner captures cover the default light/dark layout. |
| Personal work and approvals                        | Native own-draft tests and Publishing Studio assignment/deadline/review tests cover scope and separate direct/shared counts. A real optional-provider browser fixture renders the inherited English approval and opens its comparison page; the fixture retains unrelated installation-health failures.                                                                                                                                                                                                                  |
| Urgent summary, attention, publishing and activity | Purpose-designed widgets separate urgency and improvements, publishing readiness and meaningful activity. Verified recovery persists in bounded actor preferences, rechecks current permissions and contributor access, follows an authorised live UUID replacement, shows a 60-second confirmation and retains an accessible site/timezone history. Optional content status and recently updated widgets remain hidden by default.                                                                                      |
| Analytics                                          | `BuildPageViewsActionTest.php` covers complete coverage, valid zero, disconnected, restricted role, failed fetch and stale cached figures. `VisitorsSnapshotTest.php` covers configured single-site visitor mapping and omits All-sites visitor totals. `BuildCompleteAnalyticsPeriodActionTest.php` covers complete source-local periods across DST. Feature tests exercise scope and stale/failure states; configured external providers require their own source-specific integration acceptance.                     |
| Popular and rising                                 | `PageRankingsTest.php` covers populated and insufficient states, mapping, site selection and thresholds. The default source cannot supply page rankings; configured external providers need separate integration evidence.                                                                                                                                                                                                                                                                                               |
| Accessibility and visual acceptance                | Fresh shared-runner light/dark captures use clean source f74d5d917 and runner d37f411, with zero browser/backend diagnostics. Native browser checks cover populated/empty states, 390px stacking, keyboard chooser/reorder/resize, Save/Cancel/restore and master fallback. The optional fixture proves English approvals and the recovery/history/acknowledgement flow; it recorded one unattributed resource 404 and is diagnostic evidence only.                                                                      |
| Full verification                                  | The guarded local 19-cell matrix passed on fa57ce5b2, and the complete hosted matrix passed on b1ef168dd after recovery integration. Current final-head preflight, coverage and companion results are recorded in the linked PRs. This inventory does not grant release approval.                                                                                                                                                                                                                                        |

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
page only when the provider supplies separate page-creation evidence. Provider-specific integrations must verify their labels, scope, freshness, completeness and browser behaviour against the configured source.

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
Retention is subject to the configured cache store retaining permanent entries. Contract tests cover this bridge. An installed external provider needs source-specific integration and browser acceptance.

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
per user in the existing `admin_workspace_preferences` JSON column. Recovery observations and history use the same actor-owned storage, bounded to 20 scopes, 100 observations per scope and 50 history records; disabling the feature preserves that state. Site-specific
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
