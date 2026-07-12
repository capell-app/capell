# Screenshot State Guide

Screenshots should show real product states that help someone debug or understand the workflow. Avoid one perfect happy-path screenshot when the feature has meaningful states.

## State Matrix

| Area             | States to capture                                                                                                                      | Current paths                                                 |
| ---------------- | -------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------- |
| Installer        | Fresh form, preflight failure, install guide, progress running, success/removal                                                        | `packages/installer/docs/images/screenshots/*`                |
| Marketplace      | Unconnected, connected, domain verification failed, catalogue browsing, detail overview, docs/access, mobile detail, update advisories | `packages/marketplace/docs/images/screenshots/*`              |
| Admin extensions | Extensions page empty/installed, package settings page, permission denied                                                              | Add under `packages/admin/docs/images/screenshots/*`.         |
| Frontend safety  | Normal anonymous page, cached page, authoring beacon disabled/enabled for admin                                                        | Add under frontend docs when frontend-authoring is installed. |

## Naming

Use names that describe the state, not the implementation:

```text
marketplace-unconnected.png
marketplace-connected.png
marketplace-domain-verification-failed.png
installer-preflight-failed.png
installer-success-remove-package.png
```

Include dark mode only when the visual state differs or the docs page already presents paired light/dark screenshots.

## Capture Rules

- Use seeded data that is safe to publish.
- Hide secrets, emails, signing values, tokens, local paths, and private domains.
- Capture the whole viewport unless the docs need a tight crop of one control.
- Keep mobile screenshots for workflows that materially change on mobile.
- Update `screenshots.json` beside package docs when the package already uses one.

## Filament Actions

Custom Filament action behaviour needs screenshot coverage. Do not count raw actions; count states a reviewer cannot infer from a normal resource screenshot.

Standard `CreateAction`, `EditAction`, `DeleteAction`, `DeleteBulkAction`, `RestoreAction`, `ForceDeleteAction`, `ReplicateAction`, and view actions do not need a dedicated action screenshot when they are used without extra behaviour. The resource list, create, edit, or view screenshot is enough.

Add a screenshot when an action:

- uses `Action::make()`, `BulkAction::make()`, `StaticAction::make()`, or a package-owned action class for a real workflow;
- adds `form()`, `schema()`, wizard steps, modal content, footer actions, confirmation text, custom callbacks, mutations, or custom URLs;
- handles import, export, publish, unpublish, sync, clone, preview, install, connect, retry, impersonation, token, or destructive behaviour.

Link the screenshot manifest back to the source file with `covers`:

```json
{
    "id": "publishing-studio-compare-readiness",
    "target": "CompareReadinessPage",
    "covers": [
        "packages/publishing-studio/src/Filament/Pages/CompareReadinessPage.php"
    ]
}
```

Run `npm run docs:filament-action-screenshots` before finishing package work. New gaps fail `npm run docs:screenshots:check`.

The action checker scans this host repository by default. Set `CAPELL_PACKAGES_REPO` to an absolute companion-repository path only when you intentionally want to include that repository in the same check.

## Flux Visual Companion Diagrams

Flux-generated diagrams are useful for conceptual visuals at the top of docs pages. They should not be the source of truth for class names, method names, or exact request flow.

Use Flux for:

- high-level architecture visuals;
- onboarding illustrations;
- state overview graphics;
- docs covers or section headers.

Use Mermaid for:

- exact class and registry flow;
- sequence diagrams;
- testable architecture;
- anything with code symbols.

The FLUX connector must be authenticated before generating these assets. Once authenticated, create images under `docs/images/diagrams/` and keep the exact Mermaid diagram beside the image in the same doc.

## Next

- [Architecture diagrams](../reference/architecture-diagrams.md)
- [Marketplace debugging](../operations/debugging-marketplace.md)
- [Installer overview](../../packages/installer/docs/overview.md)
