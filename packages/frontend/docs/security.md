# Frontend Security

![Capell Frontend Security screenshot](./images/screenshots/frontend-settings.png)

## HTML Content Rendering

Capell sanitizes CMS HTML content by default through `RenderHtmlContentAction`.
The default mode strips unsafe HTML and leaves Blade directives inert.

`capell-frontend.html_content_allowed_attributes` controls the extra attributes
the sanitizer allows on safe elements. The packaged config allows `class` so
trusted theme output can keep utility classes. Keep this list narrow for
editor-controlled CMS content; do not add event handler attributes or anything
that exposes authoring-only state.

## Public View Query Guard

Public Blade views should render data that has already been loaded by the
frontend resolver, renderer, theme adapter, or app-level preparation action. In
local and test environments the public view query guard reports database queries
that run while public Blade is rendering, because those queries usually mean a
view is lazy-loading a relationship or reading settings after the render
boundary.

When the guard fails it logs all captured query shapes, page/layout/theme IDs,
and the Blade view paths found in the query stack. The exception includes the
first query shape and first Blade view to make the lazy-load source easier to
find.

This is a strict development guardrail, not a production dependency. It exists
to keep public rendering boring: views receive hydrated data, Actions and
adapters do the loading, and public pages do not hide query work inside Blade.
That makes page output easier to cache, review, test, and reason about.

Configure it with:

- `CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_ENABLED=true|false`
- `CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_MODE=exception|log`
- `CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_DOCS_URL=https://...`

Leave it enabled while developing public render paths. If you need a temporary
escape hatch during a local investigation, set
`CAPELL_FRONTEND_PUBLIC_VIEW_QUERY_GUARD_ENABLED=false` or set
`capell-frontend.public_view_query_guard.enabled` to `false`.

Use `exception` mode when changing public rendering, themes, layout output, or
package frontend views. Use `log` mode when you need to collect evidence across
a larger request surface without blocking local browsing.

### What It Catches

- Lazy-loaded Eloquent relationships inside public Blade.
- Settings reads from views instead of renderer preparation.
- Package view code that reaches into the database during output.
- Theme or chrome components that rely on model methods instead of prepared
  render data.

### How To Fix A Failure

1. Read the first Blade view path in the exception.
2. Move the data load to the resolver, renderer, theme adapter, or app-level
   preparation action that owns the render boundary.
3. Pass the result as render data, frontend context data, or a prepared request
   attribute.
4. Keep Blade responsible for formatting the prepared value only.
5. Rerun the failing page or test with the guard enabled.

Do not fix guard failures by adding queries to a different partial. If a public
view needs a model relationship, load it before `PublicViewQueryGuard::guard()`
starts. If a package view needs settings, resolve those settings in a package
Action or render-data contributor and pass the value through.

### Related Guardrails

The query guard is one part of the public output contract:

- `AssertPublicRenderContractAction` checks public responses for authoring
  metadata, signed editor URLs, and other unsafe output.
- Widget interactions use the optional locator contracts to consume prebuilt,
  host-neutral URLs. When no secure resolver is installed, widget targets are
  omitted; Frontend never serializes saved widget content into public URLs.
- Public render performance budgets keep package and theme rendering work
  visible during tests.
- Package manifests declare `performance.cacheSafety`, invalidation sources,
  frontend render budgets, and health checks before capability is installed.
- `capell:doctor` and package health checks report missing runtime
  configuration before public pages depend on it.
