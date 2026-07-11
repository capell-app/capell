# Capell Foundation README Artwork Series Design

## Objective

Create a coordinated artwork family for Capell Core, Admin, Frontend, Installer, and Marketplace. Each package receives a 2880×960 README hero and an independently composed 800×500 marketplace card. The artwork must feel premium and cohesive while treating real Capell screenshots as the only source of product UI, interface text, workflow states, and product claims.

## Shared Art Direction

The series uses a warm-white architectural canvas with ink and navy structural planes, restrained amber highlights, fine grid lines, and shallow atmospheric depth. Generated artwork supplies only abstract environment, lighting, material, and connective geometry. It must contain no logos, UI, text, watermarks, badges, screens, or implied product claims.

Real screenshots are composited as crisp browser or application panels. Panels use consistent corner radii, edge highlights, controlled shadows, and only mild perspective so interface text remains sharp. Light captures lead the series; dark captures may appear selectively for contrast. Screenshot content is not repainted or regenerated.

The heroes share a cinematic, layered composition with generous negative space and multiple narrative panels. Marketplace cards are separate designs with fewer, larger elements and one dominant package idea; they are not crops of the heroes.

## Package Compositions

### Core

The hero presents Capell's underlying platform as layered page hierarchy and settings/configuration panels above an abstract structural data grid. Fine connector lines and modular planes suggest sites, languages, URLs, themes, registries, and extension contracts without labeling or inventing interfaces. The card reduces this to one dominant page-structure panel supported by a smaller settings plane over the grid.

### Admin

The hero anchors on a real, visibly expanded Filament navigation hierarchy. A recognizable page table, page editing form, and dashboard or settings screen form a legible working environment around it. The table and form remain large enough to read at README width. The card focuses on the expanded navigation and page-editing relationship, with the table retained as a supporting layer.

### Frontend

The hero reads left to right. Several abstract domain/request lanes enter real or deterministic resolution stages for site and locale, continue through translated page content and theme selection, and terminate in a large real Foundation theme capture shown across responsive public outputs. Generated marks provide directional flow only; labels and interface states come from shipped captures or deterministic typography added during composition. The built-in default/demo theme must not appear as the final result. The card concentrates on multilingual request lanes resolving into one prominent Foundation public page.

### Installer

The hero is a calm stepped progression through real shipped states: package selection, environment/preflight confidence, installation progress, and successful handoff. Existing captures are used where they express these states accurately; missing states may be recaptured from the real installer. The temporary bootstrap nature is conveyed through a path that visually resolves and clears behind the final panel. The card uses the strongest package-selection or progress screen with a restrained completion cue sourced from a real shipped state.

### Marketplace

The hero follows catalogue discovery into extension detail and access evaluation, then acquisition and a queued installation/operation state. Only real Marketplace and installed-Extensions screens are used. The card focuses on the catalogue-to-detail decision, with queued operation status as a smaller factual supporting panel.

## Production Pipeline

1. Inventory and inspect existing light/dark screenshots at full resolution.
2. Capture only missing approved states from a real local Capell installation or companion package environment.
3. Generate one high-quality, text-free atmospheric source per package using the built-in image-generation service.
4. Build deterministic composites from generated backgrounds and real captures using a repeatable local script and fixed package-specific layout data.
5. Export sRGB progressive JPEGs at the exact target dimensions with quality tuned to preserve interface text without unnecessary repository weight.
6. Update each README's opening image and descriptive alt text. Preserve all standalone documentation screenshots.

The compositing source will remain deterministic and reusable so future screenshot refreshes do not require rebuilding the art direction by hand.

## Output Paths

- `packages/{package}/docs/assets/readme/hero.jpg`
- `packages/{package}/docs/assets/marketplace/extension-card.jpg`

The package names are `core`, `admin`, `frontend`, `installer`, and `marketplace`. Existing marketplace paths in `capell.json` remain unchanged.

## Validation

- Confirm all heroes are exactly 2880×960 and all cards exactly 800×500.
- Confirm JPEG files are progressive and tagged or converted to sRGB.
- Inspect every asset at full size and each card at rendered marketplace size for sharpness, cropping, hierarchy, perspective, and family consistency.
- Verify Admin visibly contains expanded navigation, a table, and a form.
- Verify Frontend communicates multiple domain requests, site and locale resolution, translated content, theme rendering, and a final real Foundation theme.
- Verify Installer and Marketplace contain only shipped screens and states.
- Verify every README image reference and every existing `capell.json` marketplace asset path resolves.
- Run the repository documentation screenshot validation gate and the narrow manifest/marketplace asset tests.
- Review all ten outputs together before committing the final implementation.

## Safety and Scope

Unrelated working-tree changes are preserved and excluded from commits. Generated source and intermediate files are retained only when they are useful for reproducibility; discarded generation variants and temporary render files are not committed. No existing standalone documentation screenshot is replaced.
