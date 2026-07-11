---
applyTo: 'packages/admin/**/*.php,packages/*/src/Filament/**/*.php'
---

# Filament Admin

- Keep Filament resources, pages, widgets, and schemas declarative. Move behavior into Actions or small services.
- Use translation keys and method overrides for labels/navigation. Avoid static label strings when local convention uses methods.
- Use enum options via backed enums implementing Filament label contracts instead of inline arrays.
- Follow sibling resources for schema layout, table columns, filters, actions, and tests before introducing a new pattern.
- Never leak admin/editor assumptions into public frontend rendering.
