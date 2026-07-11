# Capell Foundation README Artwork Series Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce and wire five factual 2880×960 README heroes and five independently composed 800×500 marketplace cards for Capell's foundation packages.

**Architecture:** Generate five non-semantic atmospheric backgrounds at high fidelity, then use one deterministic ImageMagick-based compositor driven by package-specific shell data to place real Capell screenshots. Keep source screenshots untouched, export progressive sRGB JPEGs, and validate dimensions, references, manifests, screenshot gates, and the complete visual family.

**Tech Stack:** Built-in image generation, ImageMagick 7, POSIX shell, repository screenshot tooling, Pest, Markdown, JPEG/sRGB.

---

## File Map

- Create `artwork/foundation-series/backgrounds/{package}.png`: selected generated atmospheric sources used by the deterministic compositor.
- Create `artwork/foundation-series/render.sh`: shared rendering primitives, package layouts, sRGB/progressive JPEG export, and exact-dimension assertions.
- Create `packages/{package}/docs/assets/readme/hero.jpg`: README hero for each foundation package.
- Replace `packages/{package}/docs/assets/marketplace/extension-card.jpg`: independently composed marketplace card for each package.
- Modify `packages/{package}/README.md`: opening hero path and package-specific descriptive alt text.
- Use `../capell-packages-4/packages/theme-foundation/docs/screenshots/foundation-homepage.png` and responsive companion captures as factual Frontend inputs without modifying them.

### Task 1: Audit and Select Factual Screenshot Inputs

- [ ] Inspect every candidate screenshot at original resolution with an image viewer, recording the strongest light/dark inputs for each required narrative.
- [ ] Confirm the Admin selection visibly includes expanded navigation, a table, and a form; use `admin-pages-list.png`, `02-edit-page-save-as-draft.png`, and `admin-dashboard.png` unless inspection identifies a sharper shipped equivalent.
- [ ] Confirm the Frontend terminal capture is the real Foundation theme from `../capell-packages-4/packages/theme-foundation/docs/screenshots/`, not `frontend-published-page.png` when that capture represents the built-in default theme.
- [ ] Confirm Installer and Marketplace screenshots visibly represent shipped states. If package selection, progress, completion, acquisition, or queued-operation state is absent, use the repository's existing screenshot fixtures and Playwright workflow to recapture only those missing real states.
- [ ] Run `identify` over selected files and require sRGB PNG inputs with sufficient source resolution for their planned panel size.

### Task 2: Generate Five High-Fidelity Atmospheric Sources

- [ ] Generate one source per package with the built-in image service using the shared prompt contract: warm-white architectural canvas, ink/navy structural depth, restrained amber light, fine grids, no text, no logos, no UI, no screens, no watermarks, no badges, and no claims.
- [ ] Tailor only abstract geometry to the narrative: structural layers for Core, clustered work planes for Admin, left-to-right flow for Frontend, stepped path for Installer, and discovery-to-operation arc for Marketplace.
- [ ] Inspect each generation at full size. Reject any source containing letter-like marks, fake interface fragments, logos, or overly strong detail behind planned screenshot positions.
- [ ] Copy selected outputs to `artwork/foundation-series/backgrounds/{package}.png` and record the final five prompts in `artwork/foundation-series/README.md` for reproducibility.
- [ ] Commit the five selected background sources and prompt record with `git commit -m "art: add foundation package atmospheres"`.

### Task 3: Build the Deterministic Compositor

- [ ] Create `artwork/foundation-series/render.sh` with strict shell settings, fixed repository paths, package iteration, and helpers for rounded screenshot masks, navy edge treatment, amber keyline, soft shadows, and mild perspective.
- [ ] Implement separate `render_hero_<package>` and `render_card_<package>` functions so cards have independent layouts rather than hero crops.
- [ ] In the Frontend layouts, add deterministic abstract request lanes and concise composition labels only where required to make domain → site → locale → translated content → theme → Foundation output unambiguous; never edit text inside screenshots.
- [ ] Export with ImageMagick using `-colorspace sRGB -interlace Plane -sampling-factor 4:4:4 -quality 88`, resize/crop to exact targets, strip nonessential metadata, and fail when output dimensions differ from 2880×960 or 800×500.
- [ ] Run `bash artwork/foundation-series/render.sh` and require all ten assets to render without warnings or missing inputs.
- [ ] Commit the compositor separately with `git commit -m "art: add deterministic foundation artwork compositor"`.

### Task 4: Render and Review the Five README Heroes

- [ ] Render all five heroes and inspect each at original resolution for sharp text, safe cropping, consistent panel depth, restrained perspective, and absence of generated semantic content.
- [ ] Review Core for clear hierarchy/configuration depth and Admin for the required expanded menu, table, and form.
- [ ] Review Frontend for visible multi-domain and translated URL resolution before rendering, with the real Foundation homepage as the dominant final result.
- [ ] Review Installer for a truthful selection/check/progress/handoff sequence and Marketplace for catalogue/detail/access/acquisition/queued-operation progression.
- [ ] Make only targeted layout or background adjustments, rerender the family, and repeat full-size inspection until all five pass.

### Task 5: Render and Review the Five Marketplace Cards

- [ ] Render all five cards from their independent layout functions and inspect at both 800×500 and a 400×250 preview.
- [ ] Confirm each card has one dominant idea, fewer/larger panels than its hero, readable focal UI, and no accidental hero-style cropping.
- [ ] Compare the five cards side by side for common palette, panel treatment, edge quality, amber restraint, and distinct package identity.
- [ ] Make targeted card-only adjustments and rerender until the family and individual small-size views pass.

### Task 6: Wire README Heroes and Validate Asset References

- [ ] Replace the first image in each package README with `docs/assets/readme/hero.jpg` (installer and marketplace omit the existing leading `./`) and descriptive alt text specific to the depicted package narrative.
- [ ] Do not remove or modify any standalone files under `packages/{package}/docs/images/screenshots/`.
- [ ] Run a local reference check that parses the five README opening image paths and five `capell.json` marketplace image paths, resolves each against its package directory, and fails for any missing file.
- [ ] Run `identify` on all ten outputs and require exactly five `2880x960` heroes and five `800x500` cards.
- [ ] Run `identify -verbose` and require `Colorspace: sRGB` plus JPEG interlace/progressive encoding for every output.

### Task 7: Repository Validation and Final Commit

- [ ] Run `npm run docs:screenshots:check`; require exit code 0.
- [ ] Run `vendor/bin/pest packages/core/tests/Unit/Manifest/ManifestV3ValidatorTest.php packages/core/tests/Unit/Support/MarketplaceAssetUrlTest.php packages/marketplace/tests/Unit/Support/MarketplaceSupportDataTest.php --configuration=phpunit.xml`; require all selected tests to pass.
- [ ] Run `git diff --check`; require no whitespace errors.
- [ ] Inspect `git status --short` and stage only the artwork series, README changes, and final ten assets. Preserve the pre-existing Core/Frontend working-tree changes.
- [ ] Review a generated contact sheet containing all five heroes and all five cards one final time.
- [ ] Commit the completed implementation with `git commit -m "docs: add foundation package artwork series"`.
