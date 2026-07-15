# Host, Package, Or App Code

![Capell Host, Package, Or App Code screenshot](../images/admin-dashboard.png)

Use this page before adding a feature, guide, command, or extension point. Capell stays maintainable when each change lands where it is owned.

## Decision Table

| If the change...                                                                                                                        | Put it in...                              |
| --------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------- |
| Changes sites, pages, layouts, themes, media contracts, package discovery, install/upgrade primitives, or shared settings support       | `capell-4/packages/core`                  |
| Changes the Filament panel, admin resources, dashboards, permissions, settings UI, admin bridges, or editor workflows                   | `capell-4/packages/admin`                 |
| Changes public request resolution, page rendering, render hooks, frontend settings, public HTML safety, or frontend package integration | `capell-4/packages/frontend`              |
| Changes installer screens, install cleanup, or browser installer flow                                                                   | `capell-4/packages/installer`             |
| Changes Marketplace browsing, account linking, package acquisition, or install authorization records                                    | `capell-4/packages/marketplace`           |
| Adds a product feature that can be installed, disabled, versioned, or sold independently                                                | `../capell-packages-4/packages/<package>` |
| Adds one-off project glue for a client app                                                                                              | the consuming Laravel app                 |
| Documents a feature owned by a companion package                                                                                        | that companion package docs               |
| Documents public marketing, brand, or sales copy                                                                                        | the Capell marketing docs repository      |

## Package Is The Default For Features

Create or update a package when the feature has its own:

- routes, commands, jobs, events, or settings;
- Filament resources, pages, widgets, or admin tools;
- public frontend output;
- migrations or package-owned data model;
- install command or Marketplace surface;
- tests and release cadence.

Do not hide optional feature behavior in Core, Admin, or Frontend just because those packages expose the extension point.

## Host Repo Owns Contracts

The host repo should document contracts that packages consume:

- admin bridges and admin surface contributions;
- schema extenders and lifecycle events;
- render hooks and frontend asset registration;
- cache invalidation and static export hooks;
- public HTML safety rules;
- package discovery, manifests, and installer contracts.

The package repo should document the package feature itself.

## Editorial Workflow Ownership

Foundation owns publication invariants and the seams that optional editorial
products consume: visibility-state classification, typed publication transition
request/result data, publish-readiness data, workflow-attention contributions,
publish-panel extenders, and safe public-output rules.

Publishing Studio owns the advanced collaboration implementation. Approval
records and decisions, reviewer assignments, release workspaces, field comments,
review notifications, atomic release orchestration, and rollback history belong
in `capell-app/publishing-studio`. Core and Admin must not add parallel models,
Actions, resources, migrations, or notifications for those concepts.

Publishing Studio contributes those workflows through manifests, registries, and
tagged Admin extenders. It may consume foundation publication/readiness contracts,
but it must not replace the foundation state machine or mutate public visibility
outside the shared publication and workspace-finalization invariants.

## Install Patch Ownership

Core owns the install and upgrade primitives: the file editors in
`Capell\Core\Support\Patching` (`PhpFileEditor`, `ConfigArrayEditor`, `EnvFileEditor`),
the `Capell\Core\Support\Patching\Patch` contract with its `PatchStatus` enum, and the
`Capell\Core\Support\Install\InstallPatchRegistry` seam. These exist exactly once — do
not copy an editor or the patch contract into another package.

The installer owns the installer flow: the install guide UI, its `PatchRegistry`
catalogue, and the concrete patch classes under
`Capell\Installer\Support\InstallGuide\Patches`. Patches that must run during
`capell:install` are contributed through the Core `InstallPatchRegistry` from the
installer's service provider (a factory per patch, keyed on the install selection
context, optionally with an interactive confirmation). Core evaluates the registered
factories and applies the patches without importing any installer class — Core never
depends on `Capell\Installer`, and the Core arch test enforces this.

## Next

- [Package authoring](../packages/README.md)
- [Extension point chooser](../packages/extension-point-chooser.md)
- [Docs ownership](docs-ownership.md)
