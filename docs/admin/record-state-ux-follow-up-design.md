# Admin Record State UX Follow-up Design

**Task:** CAP-0080
**Date:** 2026-08-01
**Status:** Implemented and locally verified — 2026-08-02

## Objective

Extend the completed record-state visibility work so editors can find,
understand, and safely act on exceptional records without opening every
resource. The follow-up applies the same availability, publication, usage, and
integrity language to discovery filters, navigation, deletion decisions, and a
small health summary.

It remains an authoring-only improvement. It does not automatically delete,
enable, publish, repair, or change customer-visible output.

## Chosen Approach

Use a layered experience rather than a new broad dashboard or a package-wide
mechanical rewrite:

1. Surface exceptional state in the resource where an editor is already
   working, using saved filter-compatible table filters and visible tab/count
   affordances where the resource already supports them.
2. Make non-zero relationship counts actionable by linking to the filtered,
   authorized list of known references.
3. Show a narrow impact summary before destructive actions, based only on
   relationships that the owning package can resolve safely and completely for
   that action.
4. Aggregate only the highest-value signals in the existing Layout Builder
   health widget. Do not add a competing global dashboard.

This preserves the initial presentation contract and keeps all fact discovery
in the record-owning package.

## Discoverability

Each supported resource gets a small, explicit set of exceptional-state
filters. Filter labels and counts use the existing translated state language;
they are never icon-only.

| Resource      | Filters / discovery states                                  | Count meaning                                                                                                            |
| ------------- | ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| Pages         | No active URL, scheduled, expired, draft, and URLs disabled | Count is the current scoped query result, never a global claim.                                                          |
| Layouts       | Disabled and unused                                         | `Unused` is available only from the existing exhaustive variation-aware layout-use aggregate.                            |
| Media         | Unused or no tracked uses                                   | The label follows the attachment tracker authority flag.                                                                 |
| Widgets       | Unused and unavailable                                      | Unused uses the complete Layout Builder-owned usage boundary; unavailable does not imply removal of existing placements. |
| Widget assets | Broken reference and unscoped                               | These are integrity states, not usage states.                                                                            |

Tables retain their ordinary default order and quiet enabled/in-use rows.
Exceptional filters may appear as tabs only when the resource already uses
tabs; otherwise they are table filters with query indicators. This avoids
inventing a divergent table-navigation pattern.

## Actionable State and Reference Navigation

Counts answer a dependency question only when they lead to the evidence behind
the count. Positive count metadata therefore links to the corresponding
authorized, filtered resource list:

- page URL counts link to the page URL management context;
- layout use counts link to pages using that layout, across registered page
  variations;
- widget layout counts link to layouts with the widget key filter;
- media usage counts retain the existing usage list;
- valid widget asset references link to the owning target where the target
  still exists and the actor is authorized.

If a reference list cannot be represented by a stable, authorized query, the
count remains plain metadata. No per-row queries are introduced to construct
links.

Headers and confirmation copy state the consequence in plain language. For
example, a scheduled page with no active URL explains that scheduling alone
will not make it publicly reachable; a disabled layout says that it is not
available for new use. Tooltip text may supplement this copy but never carry
the only explanation.

## Safe Destructive Decisions

Before a delete action is confirmed, the resource resolves a typed impact
summary for the specific record(s): known direct references, their count,
whether that count is authoritative for the action, and a safe navigation URL
where possible.

The confirmation has three outcomes:

- **No known references:** retain the normal confirmation. Say `Unused` only
  when the relevant usage query is authoritative; otherwise say `No tracked
uses`.
- **Known references:** show the number and type of direct records that will
  be affected or left without their dependency. Provide a view-uses link when
  available.
- **Broken / incomplete evidence:** identify the record as requiring review;
  do not convert it into an "unused" cleanup suggestion.

The follow-up does not change deletion authorization or add a new cleanup bulk
action. Existing domain deletion behaviour remains the authority. Bulk-action
confirmations show aggregate counts and exceptional selections, not an
unbounded list of record names.

## Selects, Relations, and Search

The shared select-option contract is adopted only by selectors that expose a
page, layout, widget, or referenced target to editors. Existing selected
records must keep rendering even if unavailable. New-selection eligibility
continues to be enforced by the relevant field rule rather than by a decorative
badge.

The focused adoption inventory covers page/navigation/link/redirect choices,
layout pickers, widget pickers, and widget-asset target pickers. It does not
attempt to update every package-owned Filament field. Surfaces outside the
shared contract become a separately ranked extension-adoption task.

Global search keeps the compact state summary already established in the first
slice. It does not grow a second set of filters or counts.

## Compact Health Summary

Layout Builder's existing `LayoutHealthFilamentWidget` is the single aggregation
surface for this follow-up. It receives scoped counts and deep links for:

- unused or unavailable widgets;
- broken or unscoped widget assets;
- disabled or unused layouts when the admin resource is available;
- page reachability exceptions when the current actor can access Pages.

Every card describes its scope and uses the same authority-aware wording as the
underlying table. It is deliberately a short work queue, not an analytics
dashboard: no trends, no historical scoring, no background scan, and no
cross-site totals the actor cannot inspect.

## Architecture and Query Rules

- `packages/admin` owns presentation data, reusable filters, impact-summary
  display, and Admin resource wiring. It does not own Layout Builder models.
- Each owning package supplies an Action plus immutable Data object for its
  queryable state or destructive impact facts.
- Every list, picker, header, health card, and confirmation preloads required
  facts or uses one bounded aggregate query. Views and closures never query.
- `authoritative` is propagated to labels and confirmations. Actor-scoped or
  extension-limited zero counts render `No tracked uses` or `No accessible
uses`, never `Unused`.
- URLs respect resource authorization, site, language, page variation, and
  current table filter conventions. A missing safe URL leaves the count
  non-clickable.
- No raw layout/widget content is decoded per table row. Existing indexed
  usage data and proven query helpers are reused.

## Verification

Focused tests cover:

- each exceptional filter's query and indicator wording;
- authoritative versus non-authoritative zero usage labels;
- action URLs and the absence of links where a safe target is unavailable;
- single and bulk deletion-impact summaries for no-use, known-use, and broken
  cases;
- selected unavailable records remaining comprehensible in shared selectors;
- health-widget query bounds, actor scoping, and deep-link parameters;
- regression coverage for existing state summaries, page variation-aware layout
  counts, and broken widget asset records.

Run the narrow Admin and Layout Builder Pest suites first, then the relevant
package static analysis. The companion repository's full analysis remains a
separate gate if its existing NavigationSelect nullable-string finding persists.

## Closeout Evidence

- Core/Admin: 46 focused tests / 297 assertions, the record-state fixture suite
  (5 / 37), and `composer analyze` pass.
- Layout Builder: 33 focused tests / 555 assertions, targeted PHPStan and Pint
  pass. The full companion analysis remains blocked only by the pre-existing
  nullable-label issue in `packages/navigation`'s `NavigationSelect`.
- Authenticated documentation captures pass: Core 12 light/dark variants and
  Layout Builder's two integrity tables, all with zero failures. The page
  layout selector capture renders Filament's HTML option markup with explicit
  Disabled and Unused layout states.
- Sol's final review approved the implementation after the media projection,
  direct-login removal, workbench-only fixture seeding, and read-only presenter
  fixes.
