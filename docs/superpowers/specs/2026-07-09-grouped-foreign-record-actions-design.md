# Grouped foreign-record actions

## Goal

Make related Capell records reachable from a Filament table row's grouped actions without displacing the row's primary edit action. Move the Pages table's public visit action into that group.

## Scope

The Pages table is the resource with both relevant foreign records: `layout` has an edit route and `type` (blueprint) is edited in a modal. Add grouped actions for those two relations only when the relation exists. Do not introduce links for unrelated resource tables that do not expose a meaningful related admin record.

## Design

Keep Page edit as the primary record action and move Visit page into the existing gray `ActionGroup`. Add Edit layout to the group, targeting the configured Layout resource's edit route, and add Edit blueprint as a modal action using the same form, heading, mutation, permissions, and save behaviour as the Blueprint table's existing modal edit action. The page query already eager loads both relationships, so no additional query is required.

The layout column also links to the same layout editor when a layout is assigned. Blueprint remains modal-only because its resource deliberately has no edit route.

## Safety and tests

Actions return no URL or are hidden when their relation is absent. Add focused Pages table tests covering the grouped Visit action, layout target, and blueprint modal configuration; retain the existing resource-surface assertion. No public rendering behaviour changes.
