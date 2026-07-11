# Filament Search Relevance Design

## Goal

Make Capell Filament resource tables and global search place records whose `name`
matches the search term ahead of records matched through secondary attributes.

## Scope

Apply the behaviour to the existing searchable Capell resources: pages, sites,
layouts, and blueprints. Preserve each resource's existing authorization, site
scope, eager loading, and searchable attributes.

## Design

Introduce one internal admin query helper for the shared ranking rules. Given a
model query, its qualified `name` column, and the current search term, it will
replace incidental model ordering and rank results as follows:

1. Exact `name` match.
2. `name` prefix match.
3. Earlier occurrence of the term in `name`.
4. Alphabetical `name`, then the primary key, for deterministic ties.

The helper will use the project's existing database-aware approach: portable
`CASE` expressions for exact and prefix matches, with a SQLite-safe position
fallback and MySQL's `POSITION` where available. All values remain bound query
parameters.

Each resource will apply the helper at its existing search-query extension
point. Table search will use the same helper only where the table's search term
is available, so resource lists and global search share the ranking semantics.

## Testing

Extend the focused global-search feature tests with records that match a shared
term through `name` and through a secondary searchable attribute. Assert the
`name` result is first, including an exact/prefix case and a deterministic tie.
Add focused resource-table coverage for the same ordering where table search is
customised.

## Non-goals

This does not change which fields are searchable, add full-text indexes, alter
permission or site scope behaviour, or change public frontend search/rendering.
