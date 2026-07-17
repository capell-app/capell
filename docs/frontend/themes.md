# Frontend Themes

Capell themes connect installed admin theme records to public rendering. The admin owns selection and customization; the frontend receives a resolved runtime with a theme definition, preset, brand tokens, and safe assets.

Layouts are the editorial bridge between page records and public templates. Theme records then decide the presentation runtime used when those layouts render.

The optional [Layout Builder package](https://docs.capell.app/packages/layout-builder) owns its editor UI and screenshot evidence.

![Theme Library admin workflow](../images/generated/admin/theme-library-admin-flow.png)

## Runtime Path

1. Admin installs a package, creates an available theme definition, or creates a custom theme record.
2. The package registers `ThemeDefinitionData` with `ThemeRegistry`.
3. The installed `themes.key` points at the registered definition key.
4. Sites either use the global default theme or an explicit `sites.theme_id`.
5. Frontend context resolves the current site, page, layout, and theme before Blade renders.
6. The `RenderHookLocation::HeadClose` hook resolves the active preset through `ResolveThemeRuntimeAction` and adds the theme-token stylesheet when available.
7. The frontend response pipeline builds hydrated public render data and produces public HTML through the configured response renderer.

The public page does not need to know that Theme Library, Customize, or Preview exist.

## Active Preset

Theme Studio settings still provide the fallback active preset. Installed theme records can override that through `meta.editor.preset.active`, which is set from the admin Customize slide-over or initialized from the first preset when an available theme definition is created.

Resolution order:

1. preview token preset from `ThemePreviewContext`
2. installed theme `meta.editor.preset.active`
3. `ThemeRuntimeSettings::activePreset()`
4. first preset in the theme definition when the configured preset is missing

If a preview token names a missing preset, runtime throws `ThemePresetNotFoundException`. Normal public rendering falls back to the first registered preset so public pages continue loading.

## Brand Tokens

Presets should use this vocabulary for shared brand values:

| Token                                                                                                             | Purpose                                                            |
| ----------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| `primaryColor`                                                                                                    | main action and link colour                                        |
| `accentColor`                                                                                                     | highlights, badges, and small visual emphasis                      |
| `neutralColor`                                                                                                    | borders, dividers, and quiet surfaces                              |
| `surfaceColor`                                                                                                    | default page surface colour                                        |
| `foregroundColor`                                                                                                 | default text colour                                                |
| `headingFont`                                                                                                     | heading font family key                                            |
| `bodyFont`                                                                                                        | body font family key                                               |
| `radius`                                                                                                          | default public component radius: `none`, `sm`, `md`, `lg`, or `xl` |
| `headingScale`                                                                                                    | heading scale: `compact`, `balanced`, or `expressive`              |
| `cardDensity`                                                                                                     | component density: `compact`, `comfortable`, or `spacious`         |
| `overlayTreatment`                                                                                                | media overlay treatment: `none`, `subtle`, or `strong`             |
| `spacing`, `alignment`, `cardStyle`, `navigationStyle`, `layoutPresentation`, `motionIntensity`, `mediaTreatment` | renderer-facing presentation choices                               |

Theme packages may include package-specific keys. Public theme code must tolerate unknown keys and continue with safe defaults.

## Assets

Theme definitions declare frontend assets in `ThemeDefinitionData::assets`. Package providers should register CSS/JS sources in PHP registration code instead of requiring every app to copy paths by hand.

Installed theme records can override package assets through clean editor state:

- `meta.editor.assets.paths`
- `meta.editor.assets.buildPath`

Legacy `meta.assets` and blueprint asset meta are only compatibility fallbacks when no editor asset state exists.

Token CSS is generated through `ThemeTokenStore`. The frontend provider adds the token stylesheet at `RenderHookLocation::HeadClose` when a registered theme and token path are available.

## Cache Invalidation

Theme changes touch public cache in two ways:

- applying a global default saves the selected `Theme`, which lets `ThemeObserver` invalidate affected site surrogate keys
- applying to selected sites saves each `Site`, which lets `SiteObserver` flush site/theme relation caches and invalidate those site keys

Do not bulk-update `sites.theme_id` from new code paths unless you also reproduce the observer cache behavior.

## Public Output Contract

Theme views and frontend contributions must not print admin/editor details into public output. Avoid:

- package names that only an admin should see
- model IDs, field paths, selectors, permissions, or signed editor URLs
- authoring markers or editor scripts
- lazy model queries from public Blade views

Use route-backed frontend tests when changing theme runtime. Assert expected public content and assert the absence of authoring surface.

## Next

- [Frontend](index.md)
- [Frontend guide](guide.md)
- [Creating custom themes](../packages/creating-custom-themes.md)
