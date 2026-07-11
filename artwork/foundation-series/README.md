# Capell package artwork sources

The Core, Admin, Frontend, Installer, and Marketplace README heroes and marketplace cards are complete Nano Banana Pro compositions. They are not produced by the retired SVG/background renderer.

![Capell Core Nano Banana artwork](../../packages/core/docs/assets/readme/hero.jpg)

## Workflow

The canonical `capell-logo.svg` is rasterized to `references/capell-logo-reference.png` at 1800px wide with transparency. That PNG is supplied to Nano Banana through its input-image option so the real Capell wordmark—not a text-only description—is present during generation.

Each hero and marketplace card is generated independently. Prompts require Nano Banana to treat the reference board as the established evidence-plane language and to create the complete semantic composition. The canonical wordmark is then composited from its rasterized alpha silhouette so model-redrawn branding cannot reach a committed asset. Package headings use the actual Sora light font from Capell App.

The generation reference sheet also includes existing Capell app artwork from `public/images/marketing` and `public/images/learn`: the architecture preview, ecosystem plane, package shelf, and architecture layers. This keeps the finished raster work in the existing evidence-plane family rather than inventing a parallel campaign language.

The approved direction used this prompt:

> Use the supplied reference sheet as the authoritative Capell artwork language: deep ink evidence plane, faint grid, restrained cyan, teal and gold relationships, softened control-surface panels, and subtle radial depth. Preserve the canonical Capell wordmark exactly in its cream-on-dark treatment. Use Sora-style light display typography for `CORE` and IBM Plex Sans-style supporting copy. Show one Laravel application boundary containing separate Admin, open Core, application-owned Frontend, and optional package surfaces. Core visibly owns `Site + Language`, `Page + URL`, and `Settings + Theme`; Admin joins through one controlled interface; Frontend remains directly connected and visibly application-owned; optional packages dock at perimeter surfaces. No heavy headings, factory imagery, machinery, physical model, pseudo-writing, people, devices, watermark, extra logo, tiny text, or clutter.

The marketplace card used the same approved direction but was independently composed at 3:2 for a safe 8:5 crop. A two-part reference supplied both the approved evidence-plane layout and isolated canonical logo. It keeps the Laravel boundary, peer Admin/Core/Frontend panels, controlled interface, package tiles, and application-owned outputs legible at 400×250.

ImageMagick is used only to rasterize the canonical logo, crop/resize approved generated pixels, composite the exact logo and Sora package heading, normalize sRGB, strip metadata, and export progressive JPEGs at exact dimensions:

- `packages/{core,admin,frontend,installer,marketplace}/docs/assets/readme/hero.jpg`: 2880×960;
- `packages/{core,admin,frontend,installer,marketplace}/docs/assets/marketplace/extension-card.jpg`: 800×500.

Verify the Core outputs with:

```bash
node artwork/foundation-series/verify-core.js
node artwork/foundation-series/verify-package-artwork.js
```

The four-perspective approval records are in `reviews/2026-07-11-core-structural-spine-review.md` and `reviews/2026-07-11-package-artwork-family-review.md`.
