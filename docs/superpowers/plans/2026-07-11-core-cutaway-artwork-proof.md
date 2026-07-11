# Core Cutaway Artwork Proof Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Capell Core hero and marketplace card with deterministic architectural-cutaway artwork using the real Capell wordmark and factual Core screenshots.

**Architecture:** A focused Node script owns SVG construction, asset embedding, SVG rasterization, and progressive JPEG export for the two Core variants. A small shell entrypoint preserves the existing artwork command while routing Core through Node and leaving the four unapproved package outputs untouched. A verification script asserts input provenance, output metadata, path integrity, and required deterministic labels.

**Tech Stack:** Node.js CommonJS, SVG, `rsvg-convert`, ImageMagick, Pest, existing documentation screenshot checks.

---

## File Map

- Create `artwork/foundation-series/capell-logo.svg`: vendored real Capell wordmark source.
- Create `artwork/foundation-series/render-core.js`: deterministic SVG builder, screenshot embedding, rasterization, and JPEG export.
- Create `artwork/foundation-series/verify-core.js`: exact dimensions, progressive/sRGB metadata, input/path, label, and file-size assertions.
- Modify `artwork/foundation-series/render.sh`: route only Core generation through the Node renderer.
- Modify `artwork/foundation-series/README.md`: document the cutaway source, inputs, rebuild command, and Core proof boundary.
- Modify `packages/core/README.md`: update the hero alt text to the new narrative.
- Modify `packages/core/capell.json`: update marketplace alt/caption copy without changing the asset path.
- Replace `packages/core/docs/assets/readme/hero.jpg`: generated 2880×960 progressive hero.
- Replace `packages/core/docs/assets/marketplace/extension-card.jpg`: generated 800×500 progressive card.

### Task 1: Add a failing Core artwork contract

**Files:**
- Create: `artwork/foundation-series/verify-core.js`

- [ ] **Step 1: Write the verification contract**

Create a CommonJS script that:

```js
const fs = require('fs')
const path = require('path')
const { execFileSync } = require('child_process')

const root = path.resolve(__dirname, '../..')
const required = [
    ['packages/core/docs/assets/readme/hero.jpg', '2880x960'],
    ['packages/core/docs/assets/marketplace/extension-card.jpg', '800x500'],
]

for (const [relativePath, geometry] of required) {
    const absolutePath = path.join(root, relativePath)
    const metadata = execFileSync('identify', [
        '-format',
        '%wx%h|%[colorspace]|%[interlace]|%b',
        absolutePath,
    ]).toString()

    if (!metadata.startsWith(`${geometry}|sRGB|JPEG|`)) {
        throw new Error(`${relativePath} has invalid metadata: ${metadata}`)
    }

    if (fs.statSync(absolutePath).size > 1_500_000) {
        throw new Error(`${relativePath} exceeds the 1.5 MB artwork limit`)
    }
}

const renderer = fs.readFileSync(
    path.join(root, 'artwork/foundation-series/render-core.js'),
    'utf8',
)

for (const requiredText of [
    'The structure beneath every site',
    'Define',
    'Connect',
    'Resolve',
    'Extend',
    'Site',
    'Language',
    'Page',
    'URL',
    'Settings',
    'Theme',
    'Extension',
]) {
    if (!renderer.includes(requiredText)) {
        throw new Error(`Renderer is missing required text: ${requiredText}`)
    }
}
```

- [ ] **Step 2: Run the contract and confirm it fails**

Run: `node artwork/foundation-series/verify-core.js`

Expected: FAIL because `render-core.js` does not exist.

- [ ] **Step 3: Commit the contract**

```bash
git add artwork/foundation-series/verify-core.js
git commit -m "test: define Core artwork proof contract"
```

### Task 2: Vendor the wordmark and implement the deterministic renderer

**Files:**
- Create: `artwork/foundation-series/capell-logo.svg`
- Create: `artwork/foundation-series/render-core.js`
- Modify: `artwork/foundation-series/render.sh`

- [ ] **Step 1: Vendor the source wordmark unchanged**

Copy `/Users/ben/Sites/capell-app/art/capell-logo.svg` byte-for-byte to `artwork/foundation-series/capell-logo.svg`, then assert equality:

```bash
cmp /Users/ben/Sites/capell-app/art/capell-logo.svg artwork/foundation-series/capell-logo.svg
```

Expected: exit 0.

- [ ] **Step 2: Implement renderer utilities**

In `render-core.js`, define explicit paths and helpers for XML escaping, `data:` URI creation, screenshot crops, logo embedding, SVG writing, `rsvg-convert` rasterization, and ImageMagick export. JPEG export must use:

```js
execFileSync('magick', [
    pngPath,
    '-colorspace',
    'sRGB',
    '-sampling-factor',
    '4:4:4',
    '-interlace',
    'Plane',
    '-quality',
    '88',
    '-strip',
    outputPath,
])
```

The SVG builder must embed only these factual inputs:

```js
const inputs = {
    logo: 'artwork/foundation-series/capell-logo.svg',
    pageStructure:
        'packages/core/docs/images/screenshots/core-page-structure.png',
    settings:
        'packages/core/docs/images/screenshots/core-settings-backed-configuration-dark.png',
}
```

- [ ] **Step 3: Build the hero composition**

Create a 2880×960 SVG with:

- warm `#f7f0e3` technical-paper canvas and deterministic grid;
- navy `#10233f` structural cutaway on the lower/right two-thirds;
- seven labeled layers: Site, Language, Page, URL, Settings, Theme, Extension;
- the real wordmark at top left, `CORE`, and `The structure beneath every site`;
- four numbered stations: Define, Connect, Resolve, Extend;
- amber `#e3a338`, blue `#3b82c4`, and emerald `#2f8f78` journey rails;
- page hierarchy and settings screenshots as large clipped operational windows with navy frames;
- SVG-native bridges, portals, machinery, arrows, and annotations only.

- [ ] **Step 4: Build the independent card composition**

Create an 800×500 SVG with its own coordinates, a proportionally correct wordmark, `CORE`, one dominant cutaway, one readable page-hierarchy display, and compact Define, Resolve, Extend stations. Do not resize or crop the hero.

- [ ] **Step 5: Route the Core render command through Node**

Replace the `render_core` body in `render.sh` with:

```bash
render_core() {
    node "$ROOT/artwork/foundation-series/render-core.js"
}
```

Retain the existing Admin, Frontend, Installer, and Marketplace functions unchanged.

- [ ] **Step 6: Render and verify the assets**

Run:

```bash
node artwork/foundation-series/render-core.js
node artwork/foundation-series/verify-core.js
```

Expected: PASS; outputs are 2880×960 and 800×500, sRGB, progressive, and below 1.5 MB each.

- [ ] **Step 7: Commit the renderer and outputs**

```bash
git add artwork/foundation-series/capell-logo.svg \
    artwork/foundation-series/render-core.js \
    artwork/foundation-series/render.sh \
    packages/core/docs/assets/readme/hero.jpg \
    packages/core/docs/assets/marketplace/extension-card.jpg
git commit -m "feat: render Core architectural cutaway artwork"
```

### Task 3: Update narrative documentation and manifest copy

**Files:**
- Modify: `artwork/foundation-series/README.md`
- Modify: `packages/core/README.md`
- Modify: `packages/core/capell.json`

- [ ] **Step 1: Document the Core proof pipeline**

Replace the Core background description with the deterministic cutaway design, list the vendored logo and two factual screenshots as inputs, explain that other packages remain on the previous renderer pending Core review, and document:

```bash
node artwork/foundation-series/render-core.js
node artwork/foundation-series/verify-core.js
```

- [ ] **Step 2: Update Core hero alt text**

Use:

Set the image alt text to “Capell Core architectural cutaway showing Site, Language, Page, URL, Settings, Theme, and Extension layers” while retaining the existing `docs/assets/readme/hero.jpg` path.

- [ ] **Step 3: Update marketplace screenshot copy**

Keep `docs/assets/marketplace/extension-card.jpg` unchanged and set:

```json
{
    "alt": "Capell Core architectural foundation cutaway",
    "caption": "Define, connect, resolve, and extend the structure beneath every Capell site"
}
```

- [ ] **Step 4: Validate formatting and paths**

Run:

```bash
npx prettier --check artwork/foundation-series/render-core.js artwork/foundation-series/verify-core.js artwork/foundation-series/README.md packages/core/README.md packages/core/capell.json
node artwork/foundation-series/verify-core.js
npm run docs:screenshots:check
```

Expected: all commands pass; the screenshot runner may report its documented optional skip when unavailable.

- [ ] **Step 5: Commit documentation**

```bash
git add artwork/foundation-series/README.md packages/core/README.md packages/core/capell.json
git commit -m "docs: describe Core cutaway artwork journey"
```

### Task 4: Inspect the Core proof at delivery sizes

**Files:**
- Inspect: `packages/core/docs/assets/readme/hero.jpg`
- Inspect: `packages/core/docs/assets/marketplace/extension-card.jpg`
- Create temporarily: `/tmp/capell-core-card-400x250.jpg`

- [ ] **Step 1: Inspect the hero and full-size card**

Open both JPEGs at original resolution and verify wordmark proportions, screenshot legibility, text hierarchy, route order, layer labels, crop safety, and clean edges.

- [ ] **Step 2: Generate and inspect marketplace-size proof**

Run:

```bash
magick packages/core/docs/assets/marketplace/extension-card.jpg -resize 400x250 /tmp/capell-core-card-400x250.jpg
```

Inspect `/tmp/capell-core-card-400x250.jpg`; all three compact stages and the wordmark must remain recognizable, and the page window must remain visibly factual UI.

- [ ] **Step 3: Correct composition issues and rerun verification**

If inspection finds overlap, illegibility, or poor balance, adjust only fixed coordinates/styles in `render-core.js`, rerender, and rerun:

```bash
node artwork/foundation-series/render-core.js
node artwork/foundation-series/verify-core.js
```

Expected: PASS after the final render.

### Task 5: Run focused and broad verification

**Files:**
- Verify only; no expected source changes.

- [ ] **Step 1: Run focused Core manifest tests**

Run:

```bash
vendor/bin/pest packages/core/tests/Unit/Manifest packages/core/tests/Unit/Support/MarketplaceAssetUrlTest.php --configuration=phpunit.xml
```

Expected: PASS.

- [ ] **Step 2: Run repository tests**

Run: `composer test`

Expected: PASS, or report pre-existing unrelated Type→Blueprint failures separately with exact failing test names.

- [ ] **Step 3: Run preflight**

Run: `composer preflight:all`

Expected: PASS, or report any unrelated pre-existing failures without folding them into this artwork change.

- [ ] **Step 4: Commit any final focused corrections**

If visual or verification fixes changed tracked files:

```bash
git add artwork/foundation-series packages/core/README.md packages/core/capell.json packages/core/docs/assets
git commit -m "fix: refine Core artwork proof"
```

- [ ] **Step 5: Confirm clean scope**

Run:

```bash
git status --short
git log --oneline -5
```

Expected: no uncommitted task changes; commits contain only the Core proof design, renderer, assets, documentation, tests, and any focused refinements.
