# Core Engraving Artwork Revision Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Core proof's flat SVG environment and full screenshot panels with a generated technical engraving, a continuous Capell Core lockup, and deterministic pastel wireframes derived from factual Core captures.

**Architecture:** A committed, text-free generated bitmap supplies only the architectural environment. The Node renderer builds every brand, copy, journey, layer, and product-evidence element deterministically in SVG before rasterizing exact progressive sRGB JPEG outputs. The verifier checks source provenance, deterministic copy, forbidden screenshot embedding, dimensions, encoding, and size.

**Tech Stack:** Nano Banana Pro, Node.js, SVG, librsvg, ImageMagick, Prettier

![Capell Core engraving proof](../../../packages/core/docs/assets/readme/hero.jpg)

---

### Task 1: Define the revised contract

**Files:**

- Modify: `artwork/foundation-series/verify-core.js`

- [x] Require the engraving source, continuous serif lockup, wireframe builders, factual screenshot provenance, exact metadata, and rejection of full screenshot embedding.
- [x] Run `node artwork/foundation-series/verify-core.js` against the revised renderer.

### Task 2: Generate the environment

**Files:**

- Create: `artwork/foundation-series/backgrounds/core-engraving.jpg`

- [x] Generate a 4K 21:9 text-free navy technical engraving with Capell's Nano Banana workflow.
- [x] Inspect the source for forbidden text, UI, logos, claims, badges, or watermarks.

### Task 3: Recompose hero and card

**Files:**

- Modify: `artwork/foundation-series/render-core.js`
- Replace: `packages/core/docs/assets/readme/hero.jpg`
- Replace: `packages/core/docs/assets/marketplace/extension-card.jpg`

- [x] Use the generated engraving as the environmental layer.
- [x] Place the real Capell wordmark and light serif `CORE` on one baseline.
- [x] Replace screenshots with deterministic hierarchy and settings wireframes using amber, powder blue, emerald, and coral.
- [x] Preserve the promise, four-stage hero journey, three-stage card journey, and seven Core layer labels.
- [x] Render exact progressive sRGB JPEG outputs.

### Task 4: Document, inspect, and verify

**Files:**

- Modify: `artwork/foundation-series/README.md`

- [x] Record the generator, prompt, source boundary, and deterministic overlays.
- [x] Inspect the 2880×960 hero, 800×500 card, and 400×250 card reduction.
- [ ] Run focused contract, package, documentation, and repository checks.
- [ ] Commit only the focused artwork revision.
