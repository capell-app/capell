# Proving a theme on public routes

Static renderer or direct Blade output does not prove a theme. Prove it through a real public route with realistic site, theme, layout and page records.

## Setup

- Reuse existing theme tests and helpers before adding fixtures.
- Keep package keys, namespaces and manifests stable unless the task changes them.
- Seed a site, the theme, a layout using the theme's sections, and at least one page with translations, then request the public URL.

## Route-backed checks

- The route renders the selected theme and preset.
- Database brand and token overrides beat package preset defaults.
- A stale saved preset falls back to the theme default.
- Every section the theme advertises renders through the route path.
- When the HTML cache is enabled, a first request and a repeat request both return the expected content, and the cached HTML contains no authoring surface.
- Public Blade runs no queries and lazy-loads no relationships.
- Optional integrations behave both installed and uninstalled.

## Public safety

Fail the check if public output contains any of these:

- Admin, Filament or Livewire authoring markers, or editor scripts.
- Signed editor URLs, model IDs, field paths, permissions, package names or package-internal selectors.
- Public Blade that calls `::query()`, `DB::`, `loadMissing()`, a direct model lookup or relationship fallback access.

`Capell\Core\Support\Security\PublicOutputLeakPolicy` lists the blocked public keys, authoring attributes and markers; assert against it rather than a hand-written list.

## Visual checks

- Inspect desktop and mobile viewports for overlapping navigation, hero, calls to action, media and footer.
- Accept a screenshot as evidence only when it comes from a real route and the browser console, page errors and failed requests are clean. Never substitute a fixture or generated image for a failed capture.
- Screenshots do not replace the cache, runtime and public-safety tests above.

## Order of work

Run the smallest relevant theme test first. Run a combined sweep only once the implementation is stable.
