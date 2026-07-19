# Frontend Package Instructions

- Public rendering must be anonymous-safe, cache-safe, and static-export-safe by default.
- Blade views receive hydrated render data. Do not query models, lazy-load relationships, or resolve editor state from public templates.
- Never emit authoring markers, model IDs, field paths, permissions, package metadata, signed editor URLs, or admin-only scripts into public HTML.
- Register components, resource usage, cache dependencies, and invalidation through typed frontend extension points.
- Treat Livewire, per-user output, cookies, sessions, and request-dependent markup as explicit cacheability decisions.
- Cover anonymous, signed-in non-admin, cache, and static-output behaviour when a rendering contract changes.
