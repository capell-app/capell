## Ownership Boundaries

- `packages/core` owns neutral CMS models, sites, pages, layouts, themes, media
  contracts, settings support, package discovery, install/upgrade primitives,
  publication invariants, and deterministic SiteSpec validation/import. It must
  not import sibling UI packages, optional package classes, or AI provider
  runtimes.
- `packages/admin` owns the Filament authoring workspace, policies, resources,
  settings shell, dashboard slots, and admin extension bridges. Domain work
  belongs in Actions and structured boundaries in Data objects; admin state must
  never enter public output.
- `packages/frontend` owns anonymous request resolution, rendering, render hooks,
  HTML caching, static-export behaviour, and public assets. Public Blade receives
  hydrated render data and must not query, lazy-load, resolve models, or expose
  authoring state.
- `packages/installer` owns the browser installer and concrete install-guide
  patches. Core owns the patch editors, `Patch` contract, and
  `InstallPatchRegistry`; Core invokes registered patches without importing an
  Installer class.
- `packages/marketplace` owns Marketplace transport, account linking, acquisition,
  entitlement/install coordination, and package-operation UI. Core remains the
  owner of package discovery and lifecycle state.
- Independently installable, disableable, versioned, or sold features belong in
  `../capell-packages-4`. Packages integrate through public contracts; they do not
  reach into one another's internals or make Core depend on their models.
- Core owns publication state, readiness, and transition contracts. Publishing
  Studio owns approvals, reviewers, release workspaces, comments, notifications,
  orchestration, rollback history, and other advanced collaboration records.
- Core owns deterministic SiteSpec import and safe media intake. Prompt execution,
  provider clients, metering, and AI authoring products belong in optional
  packages.
