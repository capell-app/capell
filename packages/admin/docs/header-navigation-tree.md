# Header Navigation Tree

![Capell Header Navigation Tree screenshot](./images/screenshots/admin-dashboard.png)

The header navigation tree is a dedicated Livewire dropdown in the Filament
topbar. It is separate from the admin tools menu because it owns its own
stateful loading, search, and pagination behaviour.

It is enabled by default. Admins can hide it from the header by disabling
`enable_header_navigation_tree` in Admin settings.

## Behaviour

- The dropdown does not load page records until it is opened.
- Single-site admins see root pages immediately.
- Multi-site admins see sites first, then root pages after expanding a site.
- Page children are loaded only when their parent is expanded.
- Branches are paginated in batches of 10, including high-volume sections such
  as news archives.
- Search runs across all permitted sites after at least two characters and
  shows each matching page with its visible hierarchy expanded to the match.

## Security and Scope

All records are resolved through header navigation Actions and
`HeaderNavigationAccessResolver`. The resolver evaluates the target site instead
of relying on the current request's Spatie team scope, so cross-site search does
not inherit the wrong permission context.

The tree only returns pages that:

- belong to a site the current admin can use,
- pass the page view policy in that site context,
- pass page-type role restrictions,
- have a resolvable admin edit URL.

Search suppresses a result if any required ancestor is not visible to the actor.
This avoids leaking restricted ancestor names, IDs, or URLs while still showing
complete paths for visible results.

## Extension Notes

Package developers should not register header navigation items via
`AdminToolItem`. That registry is for simple actions in the tools dropdown. The
navigation tree is a first-party Livewire component registered as
`capell-admin::header.navigation-tree` and rendered by
`capell-admin::components.header.actions`.
