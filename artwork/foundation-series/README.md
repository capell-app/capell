# Foundation artwork sources

The Core artwork is the structural-spine proof of the Foundation campaign. A text-free technical engraving generated with Capell's Nano Banana workflow supplies environmental depth. The real wordmark, serif `CORE` lockup, typography, labels, journey, Laravel boundary, semantic planes, controlled interfaces, extension sockets, and distinct site elevations are deterministic SVG overlays.

![Capell Core architectural foundation cutaway](../../packages/core/docs/assets/readme/hero.jpg)

The Core renderer uses:

- `backgrounds/core-engraving.jpg`, the committed 4K, 21:9 text-free engraving source;
- `capell-logo.svg`, vendored from the real Capell wordmark source;
- `packages/core/docs/images/screenshots/core-page-structure.png`, as the factual reference for the deterministic hierarchy trace;
- `packages/core/docs/images/screenshots/core-settings-backed-configuration-dark.png`, as the factual reference for the deterministic settings trace.

The generated layer contains no text, logo, UI, claims, badges, or watermark. It was generated with `gemini-3-pro-image-preview` at 4K and 21:9 using this prompt:

> Create a premium editorial architectural technical engraving for a software architecture campaign, ultra-wide 21:9. Warm ivory drafting paper with deep navy ink, restrained cross-hatching and subtle paper grain. Show one bounded rectangular Laravel application chassis, a central vertical structural spine divided into three blank planes, clean perimeter connection sockets, one calm side room joined by a single controlled interface, and a compact docked package module. Leave generous blank bays for later overlays. Architectural slabs, joints, portals and bridges only; no industrial machinery, gears, pipes, pistons, fans, conveyors, factory, warehouse, underground voids, browser windows, screens, UI, badges, signage, labels, letters, numbers, pseudo-writing, logo, watermark, people, or arbitrary marks.

Rebuild and verify the Core proof with:

```bash
node artwork/foundation-series/render-core.js
node artwork/foundation-series/verify-core.js
```

The output is a separately composed 2880×960 README hero and 800×500 marketplace card. Both are stripped, progressive sRGB JPEGs. The renderer uses ImageMagick to prepare the committed engraving for safe SVG embedding and final JPEG export, with `rsvg-convert` for SVG rasterization. The card is also inspected at 400×250.

The four-perspective review record is kept in `reviews/2026-07-11-core-structural-spine-review.md`.

The remaining Admin, Frontend, Installer, and Marketplace assets stay on the previous atmospheric renderer until the Core campaign language is reviewed. Their generated backgrounds remain temporary inputs for that legacy path:

- **Admin:** a strong vertical navy architectural spine with orderly shelves and open work planes.
- **Frontend:** multiple request lanes passing through translucent gates into a bright right-hand destination.
- **Installer:** a calm stepped route through checkpoint frames into an open final platform.
- **Marketplace:** a catalogue field, central evaluation plinth, and navy track toward an amber queue zone.

Run `bash artwork/foundation-series/render.sh` from the repository root to rebuild the Core proof plus the eight legacy package assets.
