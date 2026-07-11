# Tailwind v4 + symlinked vendor CSS

![Capell Tailwind v4 + symlinked vendor CSS screenshot](../images/generated/admin/site-health-page.png)

> **Status:** Skeleton — sections below are outlines.

## Scope

Some Capell-approved packages ship CSS that lives inside the package itself (Tailwind components, third-party styles like Swiper, etc.). When those packages are installed via Composer path repositories (symlinked during local development), Tailwind v4's CSS resolver fails to walk the symlinked package's `package.json` `exports` map. This doc explains why and how to fix it.

## Symptom

- `npm run build` fails with `Can't resolve 'swiper/css'` or similar.
- The Capell frontend post-install command flags this with a hint (PR #102).

## Root cause

- Tailwind v4's `@tailwindcss/vite` resolves CSS imports relative to the file's location on disk.
- When a Capell package is symlinked (Composer path repo), the file's "location" for resolution is the symlink target, not `vendor/capell-app/.../`.
- Tailwind's resolver does not honor Node's `exports` map, so `@import 'swiper/css'` can't find `swiper` anywhere up the tree from the real path.

## Fix (recommended)

- Import direct file paths instead of `exports` subpaths.
- Pattern: `@import 'swiper/swiper.css'; @import 'swiper/modules/autoplay.css';` rather than `@import 'swiper/css/autoplay';`.
- All Capell-approved packages follow this pattern. If you hit this with a third-party package, file an issue or alias it (see fallback below).

## Fix (fallback — `vite.config.js` aliases)

- If a package cannot be updated to direct imports, alias the subpaths in the host app's `vite.config.js`.
- `capell:frontend-after-install` will append these aliases automatically in a future release.
- Minimal example snippet.

## Related

- [Install guide](../getting-started/install.md)
- [Troubleshooting](../operations/troubleshooting.md)
- [Tailwind assets](../../packages/frontend/docs/tailwind-assets.md) — per-site Tailwind build pipeline
