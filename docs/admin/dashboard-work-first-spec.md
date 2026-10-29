# Work-first dashboard specification

## Objective and authority

Provide a useful default overview for a person running a website: what needs
them, what needs attention, and how the site is doing. This specification records
Ben's dashboard interview decisions of 28 October 2026 and his authorisation to
implement them. Use custom designs suited to each widget, building on standard
Filament extension points and existing Capell contracts. Do not build a new task
management product or make Foundation depend on optional packages.

## Capability map and implementation order

| Module                | Responsibility                                                             | Depends on                                    |
| --------------------- | -------------------------------------------------------------------------- | --------------------------------------------- |
| dashboard-shell       | Configuration, fallback, authorised registry, scope, personal layouts      | Existing Admin/Installer contracts            |
| dashboard-work        | Personal work, approvals bridge, attention, publishing readiness, activity | dashboard-shell; existing workflow contracts  |
| dashboard-performance | Typed availability, source mapping, comparisons, rankings, trends          | dashboard-shell; analytics provider contracts |
| dashboard-experience  | Widget designs, chooser, keyboard editing, responsive states               | All preceding modules                         |

Deliver coherent, independently verified slices in that order. Preserve the full
acceptance checklist across slices; partial implementation is not completion.

## Configuration and compatibility

- One master configuration switch replaces the Filament dashboard with this
  experience. Off restores the existing Capell dashboard. Use standard Filament
  hooks where possible; do not fork vendor code.
- New installations enable it by default. Existing installations explicitly opt
  in through config. Determine this through a durable installation mechanism,
  not an inference that silently enables upgrades. Document the actual keys.
- Individual widget switches remove a widget from rendering and the chooser,
  overriding personal layouts and preventing its dedicated data/background work.
  Preserve saved preferences and data. Shared work continues for other consumers.
- Explicit config maps analytics metrics and accurate source labels. Unsupported
  measures are omitted; engagement/conversions require deliberate configuration.
- Existing extension providers and saved layouts remain compatible. Optional
  packages contribute through typed contracts, without Foundation imports.

## Default widgets and information architecture

| Widget               | Required behaviour                                                                                                                                        |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Urgent summary       | One compact summary ordered by impact, visible until resolved, collapsible detail, survives hiding detailed widgets.                                      |
| Your work            | Assigned work, requested changes and own unfinished drafts; reason labelled; deadlines and requested changes prioritised; no approvals.                   |
| Needs your approval  | Direct assignments first; separate team-review count/link; page, submitter, waiting time, deadline where relevant; inspect changes before deciding.       |
| Site needs attention | Urgent problems separate from evidence-backed improvements; impact, evidence and permitted next action; diagnostics behind a link.                        |
| Audience figures     | Visitors/page views where supported, accurately labelled counts and valid previous-period comparisons.                                                    |
| Traffic trend        | Restrained chart, complete comparable periods, accurate source time zone and freshness.                                                                   |
| Publishing health    | Compact healthy status plus next scheduled items with Ready/Needs approval/Blocked states; link blockers to attention rather than duplicate action lists. |
| Popular pages        | Managed content ranked by a labelled measure; explain utility/error-page exclusions and link full reports.                                                |
| Rising pages         | Meaningful absolute and proportional growth, actual counts and percentage; distinguish new pages and insufficient evidence.                               |
| Team activity        | About five compact meaningful events, actor/action/title/time; group repeated edits; routine system events stay in full log.                              |

Work and attention lead, with a concise audience overview. Popular/rising pages
and activity appear lower down. Content status and Recently updated pages are
available to add but hidden by default. Updated-page rows include title, editor,
time and permitted edit link. No separate quick-actions widget. Relevant actions
live in widgets: view public page, inspect performance, edit when permitted.

## Workflows, permissions and empty states

- Gate every widget, row, total, action and data request by applicable permissions
  and site access, including tampered saved layout/filter requests.
- Approvals appear only for permitted reviewers, initially explaining how pages
  reach review. After the person's first completed review, use a compact caught-up
  state. Broad approval permission does not imply personal assignment.
- Pending approvals are normal work; urgency requires a missed deadline. Confirmed
  pre-publication blockers have readiness warnings without inventing urgency.
- Content age, draft age and traffic decline alone are not content defects.
  Improvement checks need concrete evidence (for example verified broken links,
  missing required content, required translations, explicit overdue reviews),
  enabled capabilities and a useful action. Goal insights need configured goals.
- Personally dismiss/snooze advisory suggestions without disabling checks for
  colleagues. Confirmed urgent problems remain until resolved. Verified recovery
  produces a brief confirmation, then leaves attention; retain accessible history.
- No personal work means 'No assigned work or unfinished drafts', with relevant
  links. Do not imply the whole site is clear. Keep established empty widgets
  compact while retaining helpful first-use introductions.
- Analytics setup guidance/actions are for configurators; other permitted viewers
  get a compact explanation without an unusable setup action.

## Analytics and scope

- One compact analytics-only period control defaults to the last 30 complete days
  against the preceding 30. Work and approvals remain current. Upcoming publishing
  has a clearly labelled future window. Respect the source reporting time zone.
- Distinguish disconnected, successful zero, insufficient data, stale and failed
  fetches. Retain last-successful figures with unmistakable stale status and last
  update time. Failed fetches never become zero traffic or false decline alerts.
- Compare sufficient complete observations; handle zero baselines without bogus
  growth percentages. Rising rankings use meaningful count and rate growth; no
  clear trend produces a compact honest state. Document chosen thresholds.
- Remember selected site. All sites labels item ownership, combines only measures
  with compatible definitions, explains coverage/missing sites and never implies
  cross-site unique visitors. Keep incompatible measures separate.
- Language filter appears only when relevant, defaults initially to all languages
  and remembers selection. Mark metrics that cannot be filtered. Urgent site-wide
  issues remain visible despite language filtering.
- Deadlines use labelled site time zones; make differing zones clear in All sites.
  Show analytics source zones where different. Relative times expose exact dates.

## Personalisation and visual behaviour

- One personal layout across individual sites; a separately remembered All sites
  layout. Only show applicable and permitted widgets. Config wins over preferences.
- Explicit Edit dashboard mode, Save/Cancel, restore defaults, keyboard-accessible
  move/size controls alongside dragging, deliberately designed size choices per
  widget. Cancelling must not persist changes. Preserve hidden widget preferences.
- A visual chooser groups Work/Content/Performance/System, with illustrative
  previews, purpose/requirements, and Added state. Extensions offer widgets without
  automatically crowding existing layouts.
- Calm compact styling, consistent spacing/controls and appropriate icons; status
  remains understandable without colour. Accessible focus, chart alternatives and
  reduced motion. No nested generic panel containers or oversized audit rows.
- Short previews, explicit totals and full-list links. Resizing reveals a few more
  rows, without internal scroll areas. Approvals get useful review detail.
- Responsive layouts preserve the default priority; customised layouts preserve
  their saved reading order, urgent summary first. No pointer-only controls.
- Refresh quietly, preserve expanded/read state and do not move active rows under
  the pointer. Offer new items before reordering; urgent status updates promptly.
  State analytics freshness without implying real-time collection.

## Source ownership and conventions

Admin owns shell, widget UI, Actions, Data and neutral extension bridges. Installer
owns fresh-install wiring. Core owns neutral publication contracts; Publishing
Studio owns advanced review records. Inspect existing providers before extending
them. If companion work is required, give it its own owned checkout and PR.

Use strict PHP 8.4-compatible types, thin Livewire/Filament entrypoints, Actions for
business operations, Data for structured boundaries, translations and real
`#[Override]` attributes. Example convention:

```php
declare(strict_types=1);

public function handle(DashboardFilterStateData $filters): DashboardAnalyticsSnapshotData
{
    return $this->provider->snapshot($filters);
}
```

## Verification and acceptance

Tests must exercise behaviour, not source strings. Record red/green regression
proof, actual exits and full log paths. All requirements above remain acceptance
criteria, with a traceable implemented/tested/outstanding matrix.

- Test master fallback, fresh versus upgrade defaults, per-widget precedence,
  disabled provider/job work and preservation of saved data/preferences.
- Test permission/site isolation, approval assignment/onboarding, no duplicated
  approvals/failures, priority, evidence-based suggestions and personal snoozing.
- Test layout Save/Cancel/reset, hidden widgets, cross-site versus All sites state,
  extension contributions, invalid/tampered layout and filter inputs.
- Fix time in analytics tests; exercise zero, insufficient/new/stale/failed states,
  period boundaries, mapping, comparable aggregation and partial coverage.
- Browser-test populated, new/empty, failure, restricted-role, multilingual and
  multi-site states; keyboard edit/save/cancel, responsive widths and dark/light
  appearance. Acceptance screenshots use the repository's shared-runner provenance.
- Run focused checks first with the owning wrapper, for example
  `./capell pest packages/admin/tests/path/ToTest.php --configuration=phpunit.xml`.
  Discover exact affected tests from symbols/callers; do not treat this example as
  a real test path.
- Run `composer lint:changed`, `composer analyze:source`,
  `composer check:extension-surfaces`, `composer check:stable-extension-api`, and
  applicable docs checks. Maintain at least 90% line coverage. Coordinate required
  full `composer preflight:all` and `composer test:all:matrix:local` through the
  repository orchestrator; no concurrent arbitrary full runs.
- Review the whole branch before each push. Open/update owned PRs, monitor exact
  head checks, fix confirmed failures and present the final diff and impact summary.
  Merge requires Ben's subsequent approval of that specific final PR. No deployment
  or destructive/shared-data operations are authorised by this specification.

## Delivery evidence

Implementation must add an inventory of existing/changed/missing capability
contracts and an acceptance matrix linked to tests and browser evidence. Do not
claim unavailable optional integrations are implemented merely because an empty
state renders. Preserve explicit outstanding work until verified.
