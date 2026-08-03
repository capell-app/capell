# Admin Record State UX Design

**Task:** CAP-0080
**Date:** 2026-08-01
**Status:** Approved design; implementation planning pending

## Objective

Make exceptional record states obvious wherever editors encounter pages,
layouts, media, Layout Builder widgets, and widget assets. Editors should be
able to answer three questions without opening a details section:

1. Can visitors currently reach or use this record?
2. What is its publication state and timing?
3. Where is it used?

The first implementation slice covers Core Admin and the Layout Builder
companion package. It improves awareness and navigation only; it does not
delete, clean up, publish, enable, or otherwise mutate records automatically.

## Research Summary

Core Admin currently exposes useful facts inconsistently:

- The pages table has a publication-status column, but the edit header and
  shared page selectors do not provide the same visibility.
- Page availability is controlled by `PageUrl` status, not a status field on
  `Page`. A page can therefore be scheduled while having no active URL.
- Layout cards show enabled state in their footer and the layouts query already
  computes page usage across registered page variations, but the state is easy
  to miss and `LayoutSelect` uses a narrower base-page count.
- Media exposes an attachment usage count, but zero has no explicit meaning or
  dedicated filter.
- Layout Builder computes direct layout counts for widgets and already reports
  unused widgets in a dashboard health widget, but it does not present the fact
  consistently in tables, headers, and pickers.
- Widget assets are assignments. Missing widget, asset, pageable, container, or
  occurrence references are integrity failures, not evidence that the record is
  unused.
- Shared HTML select options provide a practical adoption point for page,
  layout, and package-owned selectors without duplicating markup.

The wider companion-package inventory contains roughly 270 Filament surfaces
across about 60 packages. Those surfaces require a later, ranked adoption pass
rather than a broad mechanical rewrite.

## State Model

State is not a single enum. Surfaces compose independent facts in a fixed
priority order.

### 1. Availability

Availability answers whether the record can currently participate in its
public or authoring role.

Examples:

- `Disabled`
- `No active URL`
- `Some URLs disabled`
- `Unavailable`

For pages, availability is resolved from accessible `PageUrl` records with
site and language context. It must never be inferred from a nonexistent page
status attribute.

### 2. Publication

Publication continues to use the established page visibility resolver and
exact lifecycle terminology:

- `Draft`
- `In review` when supplied by an installed workflow
- `Scheduled for <date and time>`
- `Published`
- `Expired`

The phrase "pending publication" is not used as a catch-all. Scheduled details
include timezone context on detail screens.

### 3. Usage and integrity

Usage describes known inbound references:

- `Unused` only when the owning package can prove an exhaustive zero count.
- `No tracked uses` when the tracking boundary may be incomplete.
- `No accessible pages` when the current actor cannot see any references but a
  global zero cannot safely be claimed.
- `Used by <count> <records>` when a count is available.

Integrity is separate from usage:

- `Broken reference`
- `Orphaned`

Actor-scoped zero counts never authorize cleanup or deletion. Broken or
missing references are never presented as unused.

## Presentation Contract

The reusable contract belongs to `packages/admin`; Core remains neutral and
Filament-free.

Admin will provide:

- An immutable state data object containing a stable machine key, translated
  label, optional short label and description, Filament colour, Heroicon, and
  display priority.
- A composer that orders and filters already-resolved state data for a surface.
- Reusable Blade components for header summaries, card badges, and select
  options.
- Small typed table-column helpers where they reduce repetition.
- A backwards-compatible optional `states` payload on the existing custom
  select-option component.

The presentation layer does not discover domain facts or query models. Each
owning package resolves its facts and supplies translated strings from its own
namespace.

The existing `PageTableStatusResolver` and `PageTableStatusData` signatures
remain compatible. Publication state is adapted into the new collection rather
than replaced, preserving application and package bindings.

## Query Contract

State rendering performs no database queries.

- Every table or picker query loads the relations, count aliases, or aggregate
  values required by its state resolver.
- Query enrichment is surface-specific and additive. Contributors must not
  replace another contributor's selected columns.
- Layout usage extraction will reuse one Admin-owned query helper across the
  layout list, header, and picker. It will cover all registered page-variation
  models consistently.
- The current per-layout, per-page-variation count work performed while
  formatting links will be removed or deferred to an explicit usage view.
- Widget usage is owned by Layout Builder and includes direct layout
  placement, nested/composed-widget references, and applicable preset
  references before `Unused` can be asserted.
- Selected-value hydration and searchable option queries remain separate.
  Existing unavailable selections must render without adding per-option
  queries.

Site scoping and resource authorization remain intact. Where an authoritative
global count cannot be disclosed, the UI uses actor-scoped wording rather than
misrepresenting the result.

## Relationship Counts

Counts are included when they answer a useful dependency question and can be
loaded as part of the owning surface query. They are secondary metadata, not a
second badge cluster. Zero values are rendered as an exceptional state only
when they have a meaningful name such as `Unused`; routine positive counts are
shown compactly or behind an existing details/usage link.

The first slice may expose these bounded counts:

- Pages: child pages and page URLs, with active URL wording kept separate from
  the raw URL count.
- Layouts: pages using the layout across all registered page variations,
  containers, and configured widgets where those values are already available
  without decoding unbounded content in the view.
- Widgets: layouts using the widget and widget assets attached to it.
- Widget assets: owning widget, valid target references, and the relevant
  integrity count rather than a misleading usage count.
- Media: tracked asset attachments/usages, with a link to the usage list.

Counts are not added when they require per-row queries, expose an incomplete
reference boundary as authoritative, or compete with the primary record name
and action controls. Detail headers may show a fuller `Used by`, `Contains`, or
`Has` summary; table rows and selectors use the shortest useful form.

## Surface Behaviour

### Page editor

The header shows a persistent state summary immediately below the title and
before ordinary URL metadata. Exceptional availability appears first, followed
by publication.

A scheduled page without an enabled URL explains the consequence: it will not
become publicly reachable when the scheduled time arrives. Relevant actions
link to URL management or publication controls rather than hiding the next step
inside a tooltip.

### Page tables and related lists

The existing publication column remains the publication authority. Availability
is added as a separate compact treatment so combinations remain visible.

Exceptional states use visible text and a consistent icon. Whole rows are not
heavily tinted because row selection, hover, validation, deletion, and
alternating-row treatments must remain legible. Filters or tabs cover the
high-value exceptional states supported by the surface, including draft,
scheduled, expired, and no active URL.

Relation managers, recent-page widgets, and other shared page lists adopt the
same state data rather than inventing local wording.

### Page selectors and global search

Page selects, morph selects, navigation destinations, redirect/link pickers,
and package selectors that use the shared option component show exceptional
state text. A representative option is:

`Homepage — No active URL`

with secondary text explaining the public consequence. Icons and colours are
supplementary.

Already-selected unavailable records remain visible and understandable.
Whether they can be newly selected remains a separate domain eligibility rule.

Global search uses a compact ordered summary such as:

`No active URL · Scheduled 14 Aug`

It does not render a dense cluster of positive badges.

### Layout editor, cards, tables, and selectors

The layout header shows availability plus authoritative usage. Layout cards
place exceptional badges in the preview area without obscuring the title or
actions. `Disabled` and `Unused` are prominent; ordinary enabled/in-use layouts
remain visually quiet.

Non-zero usage counts link to accessible referencing pages. Layout selectors
reuse the same state data and authoritative variation-aware count used by the
list. The detail header may also show child counts for containers/widgets when
they are query-preloaded; the card keeps only the page-use count and its
existing expandable container summary.

### Media

The media table makes zero usage explicit and filterable. The label is
`Unused` only if attachment tracking is exhaustive for the supported reference
contract; otherwise it is `No tracked uses`. Editors receive a usage path
before any future cleanup action is considered. The table keeps the existing
usage count as the primary relationship count and does not add speculative
counts for untracked rich-text or package-owned references.

### Layout Builder widgets

Widget headers, tables, relation managers, and pickers show availability and
usage consistently. `Unavailable` means the widget cannot be newly placed; it
does not imply that existing placements have disappeared.

Usage resolution includes every Layout Builder-owned reference form required
to make an exhaustive claim. Positive layout and asset counts link to the
referencing records where permitted; zero counts become `Unused` only when the
same complete reference query proves zero.

### Layout Builder widget assets

Widget assets receive integrity states rather than a generic unused state.
Valid layout-level defaults may intentionally have no pageable reference.
Missing required widget, target asset, pageable, container, or occurrence
references are resolved according to the package's actual invariants and shown
as `Broken reference` or `Orphaned`. The detail surface may show the owning
widget and target counts, but the table does not imply that a nullable pageable
field is an unused asset.

## Visual and Accessibility Rules

- Exceptional states are always text-visible; colour and icons never carry the
  meaning alone.
- One semantic icon is used consistently for each state across resource types.
- Disabled or unavailable state uses explicit wording and a pause, eye-slash,
  or equivalent icon. Gray alone is insufficient.
- Tooltips are supplementary and are attached only to focusable controls when
  used.
- Compact icon-only renderings include accessible names.
- Badge contrast must meet WCAG AA in Filament light and dark themes.
- Headers may show fuller descriptions; routine tables and selectors suppress
  positive-state noise.
- State placement must not obscure record titles, actions, checkboxes, or
  validation feedback.

## Actions and Edge Cases

- The first slice adds no automatic deletion, cleanup, enabling, publication,
  or bulk mutation.
- Replication and restoration retain their existing domain behaviour, but
  confirmations must make inherited exceptional state visible where relevant.
- Bulk-action confirmations summarize exceptional selected records when the
  action's consequence depends on their state.
- Validation explains when a selected dependency is disabled or unavailable;
  it does not rely on a decorative badge alone.
- Empty states for usage filters explain the reference boundary being checked.
- Multisite and multilingual contexts retain their site/language distinctions.

## Extension and Release Contract

Layout Builder consumes the Admin-owned presentation API without Admin knowing
its models. Package-owned state contributors may supply translated data and
query enrichment through documented extension points.

Admin is released before Layout Builder adoption. Layout Builder raises its
minimum Admin version to the first release containing the contract and includes
a dependency contract test. Runtime feature detection is not used where
Composer can express the requirement.

## Verification

Focused tests cover:

- Disabled or unavailable records combined with scheduled, published, and
  unused states.
- Page URL availability across multiple languages, mixed enabled URLs, and
  deleted or inaccessible records.
- Existing custom `PageTableStatusResolver` bindings.
- Layout usage across every registered page variation.
- Global versus actor-visible usage wording.
- Nested widgets, composed widgets, presets, and direct layout placements.
- Valid widget-asset defaults and broken polymorphic references.
- Selected unavailable option retention, search, and empty preload paths.
- Escaping, accessible text, icons, colours, and light/dark rendering for
  headers, tables, cards, search results, and selectors.
- Query-count budgets with multiple rows and registered variations.
- Layout Builder compatibility with its declared minimum Admin version.

The smallest useful verification begins with resolver and component tests,
then resource feature tests, package-focused tests, formatting, and static
analysis. Broader repository checks follow only when the focused contracts are
green.

## Later Adoption Inventory

The follow-up audit records, for every candidate surface:

- owning package and model;
- table, header, relation manager, picker, repeater, dashboard, or search
  location;
- availability, publication, usage, or integrity facts displayed;
- whether counts are authoritative, tracked-only, or actor-scoped;
- query preload requirements;
- selected-value and new-selection eligibility behaviour;
- translation ownership and tests.

Adoption priority is:

1. Page, layout, and widget selectors.
2. Reusable content, media, assets, and navigation.
3. Themes, blueprints, forms, and other authoring structures.
4. Operational and reporting resources where state ambiguity has lower editor
   impact.

This inventory is tracked in the internal ledger and does not expand the first
implementation slice.
