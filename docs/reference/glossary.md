# Glossary

![Capell Glossary screenshot](../images/capell-core-composition-erd.svg)

## Editing terms

Plain-English definitions for everyday content work.

| Term | Means |
| --- | --- |
| Draft | A saved version of a page that visitors cannot see yet. You keep editing until you publish. |
| Live (published) | A page that is visible on the public site. |
| Publish | Make a page live on the public site. |
| Unpublish | Take a live page back down so visitors no longer see it. |
| Slug | The last part of a page's URL (for example `about-us`). Keep it short, lowercase, and stable. |
| Preview | A private view of how a page will look before it goes live. |
| Redirect | A rule that sends an old URL to a new one so old links keep working. |
| Alt text | A short text description of an image, used by screen readers and when the image fails to load. |
| Site switcher | The admin control for choosing which site you are currently editing. |
| Media | Uploaded files (images, documents) managed in the Media area. |
| Blueprint | The reusable definition behind a page, theme, site, element, or section. Admin labels: Page blueprint, Theme blueprint, Site blueprint. |
| Site | A top-level publishing surface with its own domain(s), languages, and settings. |
| Page | The primary routable content entity. Pages belong to a site and can have one or more language translations. |
| Workspace | An isolated sandbox where edits are staged. One workspace may hold a single page change or hundreds of coordinated edits. |

## Developer terms

Implementation-level definitions for package and theme authors.

| Term | Means |
| --- | --- |
| Draft | A page version scoped to a workspace, identified by a non-zero `workspace_id`. |
| Live | A page version with `workspace_id = 0`, served to the public. |
| Draftable model | Any Eloquent model that participates in the workspace system. Draftable models use the `BelongsToWorkspace` trait. See the [Draftable contract](https://docs.capell.app/publishing-studio-draftable-contract/). |
| Workspace | An isolated sandbox where edits are staged. Implemented via the `BelongsToWorkspace` trait. See [PublishingStudio & Versions](https://docs.capell.app/publishing-studio/). |
| Version | An immutable snapshot of the live manifest at a point in time. Publishing a workspace creates a new version and flips it live. |
| Publish | The atomic flip that promotes a workspace's draft rows to live. Runs publish-checks (freshness, URL collisions, release windows) inside a single transaction. |
| Rebase | Bringing a stale workspace up to date after another workspace has published. See [Rebase flow](https://docs.capell.app/publishing-studio/#rebase-flow). |
| Rollback | Restoring the previous live version after a publish. Also available per-row via `EntityRollbackAction`. |
| Approval level | An integer count of approvers required before a workspace can publish. Defaults to `2`; override via `settings.required_approval_levels`. |
| Schema | A Filament field schema class that defines the form for a blueprint, setting, or resource. |
| Schema hook extender | A class that injects extra form fields at named positions in an existing schema, without overriding the full schema. See [Extending Capell §4](https://docs.capell.app/extending-capell/#4-schema-hook-extenders). |
| Render hook | A registered extension that injects HTML into a named location inside a frontend Blade component. See [Render Hooks](https://docs.capell.app/extending-render-hooks/). |
| Event registry | The Admin package's event bus for subscribing to admin lifecycle events (e.g. `afterSave`). See [Extending Capell §5](https://docs.capell.app/extending-capell/#5-event-registry-callbacks--subscribers). |
| Settings Schema Registry | Runtime registry of settings form-builder shown on the admin **Settings** page. See [Settings Schema Registry](https://docs.capell.app/packages/admin/settings-schema-registry/). |
| `Layout` (core model) | The theme-scoped template structure a page renders into (`Capell\Core\Models\Layout`). An ordered, theme/site-scoped template record with a status flag, a default flag, and an attached image, selected per page. Part of core. |
| Layout Builder / Layout block (ContentSections) | Page-composition block primitives editors drag and drop, provided by the ContentSections [approved package](../packages/catalog.md). Distinct from the core `Layout` model above. **Not part of core.** |
| Migration package archive | A portable archive containing exported content, manifest data, payloads, and integrity checks that can be re-imported through the Recovery Center when `capell-app/migration-assistant` is installed. See [Recovery Center](../admin/recovery.md). |
| Static HTML cache | Rendered HTML written to disk and served directly by the web server before PHP runs. See [HTML Caching](https://docs.capell.app/cache/). |
| Render hook context | The `RenderHookContext` DTO passed to every render-hook extension, exposing the location, the current item, and any extra data the host component provides. |
| Workspace context | The active workspace for a request, set either by the admin switcher (session) or a signed preview link (cookie). Driven by the `ResolveWorkspaceContext` middleware. |
