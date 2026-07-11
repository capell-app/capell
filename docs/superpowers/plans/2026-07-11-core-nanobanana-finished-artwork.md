# Core Nano Banana Finished Artwork Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Core SVG-generated hero and marketplace card with independently art-directed, finished Nano Banana Pro raster artwork using the canonical Capell logo as a reference image.

**Architecture:** The canonical SVG wordmark is rasterized only as a Nano Banana reference input. Nano Banana owns the entire finished composition for each output; ImageMagick performs only exact resizing, colour normalization, progressive JPEG encoding, and inspection. A lightweight Node validator enforces the asset contract without generating artwork.

**Tech Stack:** Nano Banana Pro, canonical Capell SVG reference, ImageMagick, Node.js validation, Pest, documentation screenshot gates.

---

![Core artwork being replaced](../../../packages/core/docs/assets/readme/hero.jpg)

### Task 1: Prepare the logo reference and contract

**Files:**

- Create: `artwork/foundation-series/references/capell-logo-reference.png`
- Modify: `artwork/foundation-series/verify-core.js`

- [ ] Rasterize `artwork/foundation-series/capell-logo.svg` at high resolution with transparency and verify the reference dimensions and alpha channel.
- [ ] Replace renderer-source assertions with checks for exact hero/card dimensions, progressive sRGB JPEG encoding, file-size limits, logo reference existence, and review evidence.
- [ ] Run `node artwork/foundation-series/verify-core.js` before replacing outputs and confirm it fails while the obsolete renderer remains part of the workflow.

### Task 2: Generate and select the finished hero

**Files:**

- Replace: `packages/core/docs/assets/readme/hero.jpg`
- Create temporarily: `/tmp/capell-core-nanobanana/hero-*.png`

- [ ] Generate three or four 4K 21:9 candidates with Nano Banana, passing `capell-logo-reference.png` through `--input` and requesting the full structural-spine composition.
- [ ] Inspect each candidate at full size and a 960×320 reduction; reject any altered wordmark, malformed text, generic factory imagery, weak hierarchy, or misleading product boundary.
- [ ] Iterate the strongest direction until it is professionally usable, then crop/resize and encode it as a 2880×960 progressive sRGB JPEG.

### Task 3: Generate and select the marketplace card

**Files:**

- Replace: `packages/core/docs/assets/marketplace/extension-card.jpg`
- Create temporarily: `/tmp/capell-core-nanobanana/card-*.png`

- [ ] Generate three or four independent 4K card candidates with the canonical logo reference and a deliberately simpler hierarchy.
- [ ] Inspect candidates at 800×500 and 400×250; reject any card where Laravel boundary, Core, Admin, application-owned Frontend, or distinct site outcomes collapse.
- [ ] Iterate the strongest direction, then crop/resize and encode it as an 800×500 progressive sRGB JPEG.

### Task 4: Remove the SVG artwork generator

**Files:**

- Delete: `artwork/foundation-series/render-core.js`
- Delete: `artwork/foundation-series/backgrounds/core-engraving.jpg`
- Modify: `artwork/foundation-series/render.sh`
- Modify: `artwork/foundation-series/README.md`

- [ ] Remove the Core renderer invocation and obsolete background dependency without affecting legacy package artwork generation.
- [ ] Document the Nano Banana full-composition workflow, exact prompt, canonical logo reference, and prohibited programmatic composition boundary.

### Task 5: Review, verify, and commit

**Files:**

- Modify: `artwork/foundation-series/reviews/2026-07-11-core-structural-spine-review.md`

- [ ] Record independent competitive evaluator, critical Laravel developer, agency buyer, and visual-quality reviews of the same final hero/card candidate at all required sizes.
- [ ] Run the artwork contract, exact metadata checks, documentation screenshot gate, and focused Core manifest/marketplace tests.
- [ ] Confirm `git diff --check`, stage only focused artwork files, and commit while preserving unrelated dirty-tree work.

Self-review: this plan covers reference-logo fidelity, complete Nano Banana composition, independent output art direction, SVG-generator removal, thumbnail inspection, contract validation, four-perspective review, focused tests, and dirty-tree isolation.
