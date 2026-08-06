# Root README banner — regeneration recipe

`docs/images/capell-readme-hero.jpg` (2880×1222, ~320 KB JPEG q86) is a Nano Banana Pro
composition matched to the live `capell.app` homepage hero: dark green-black background
(`#071312`) with a square-grid drafting-board pattern, cream/navy 3D isometric objects,
mint/teal glow cables. It shows Core as a single foundation forking into a genuinely
separate Admin (Filament) and Frontend (Blade/Livewire/Inertia/Vue) — the two structures
must never touch above the plinth, only converge at Core.

Generated 2026-08-06 in response to the copy in this same commit restoring the README's
opening to the site's own hero language (see the commit body for that history).

## Regenerate

1. Prompt: `readme-banner-prompt.txt` in this directory. Send it with **five reference
   images in this exact order**:
   1. `packages/core/docs/assets/readme/hero.jpg` (or any current capell.app hero
      artwork) — style reference, matte 3D/clay-render language.
   2. Laravel logomark PNG, rasterized from an official SVG (e.g.
      `~/Sites/capell-app/public/images/marketing/tools/laravel.svg`).
   3. Filament wordmark PNG, rasterized similarly
      (`~/Sites/capell-app/public/images/marketing/tools/filament.svg`).
   4/5. The canonical Capell logo, navy + white — the generator script rasterizes both
      automatically from `capell-logo.svg` in this directory.
2. Use a generator that accepts **multiple** `--reference` images (the shared
   `capell-audiences/scripts/gen_with_logo.py` only takes one — copy it and add
   `action="append"` on `--reference`, or write a throwaway variant; see the session
   transcript from 2026-08-06 for a working copy).
3. `--ratio 21:9 --size 4K`.

## Known model failure modes (why the raw output is never shipped as-is)

- **Laravel's mark renders as a hollow red wireframe outline**, not the solid filled
  ribbon it actually is — reproduced across three separate attempts even with the real
  SVG supplied as a reference image. Always erase the model-drawn version and composite
  the real rasterized mark on top.
- **A crossbar/bracket sneaks in above the Y-fork** unless the prompt explicitly forbids
  "no crossbar, no bracket, no bridge" — without that line the model tends to draw Admin
  and Frontend as one joined shelf, which defeats the entire "Core is the only place they
  meet" idea.
- The **Capell wordmark itself came out correct** in every attempt here, letter-accurate
  swash and all — but per this repo's standing rule (see the paragraph below), composite
  the canonical vector regardless; don't rely on the model getting lucky twice.

## Post-processing (compositing real marks over the generation)

Per this directory's standing rule — *"the canonical wordmark is then composited from
its rasterized alpha silhouette so model-redrawn branding cannot reach a committed
asset"* — every generation needs a compositing pass, not direct use:

1. **Erase + patch** the model-drawn Laravel wireframe and wordmark: crop the region,
   run `-statistic median 45x45` over just that crop to erase it into the surrounding
   plinth texture, composite the patch back over the original coordinates. Do this as a
   tight, minimal box around the mark only — a wide patch smears the "CORE" lettering
   next to it (the patch box that also grazed the C of CORE noticeably blurred one
   letter; keep the box just wide enough for the logomark).
2. **Composite the real Laravel PNG** (rasterized from the official SVG, `#F0513F`) into
   the erased recess, rotated to match the plinth face's isometric angle (~-22°).
3. **Composite the real Filament wordmark PNG** (white, for the dark sidebar slot) — this
   one didn't need erasing/patching in the 2026-08-06 run; the model's rendering was
   close enough visually that it was fully replaced by the composite anyway, same as
   Laravel.
4. **Composite the canonical Capell wordmark** (white SVG, fill swapped from `#001d3d` to
   `#ffffff`, rasterized at the same width as the model-drawn version's measured bounding
   box) over the model-drawn one, after erasing it the same median-patch way.
5. **Build the four Frontend stack tiles** and composite them onto the docked blank tiles
   the model draws under the browser window:
   - Blade has no official logo — render `Blade` as text
     (`-font /System/Library/Fonts/HelveticaNeue.ttc -fill '#001d3d'`; plain `Helvetica`
     font names fail on this machine, must give a real font *file* path).
   - Livewire: `~/Sites/capell-app/public/images/marketing/tools/livewire.svg`.
   - Inertia, Vue: not vendored locally — fetch from `https://cdn.simpleicons.org/inertia`
     and `https://cdn.simpleicons.org/vuedotjs`.
   - Rotate each tile PNG about -8° to sit flush on the model's tile faces before
     compositing.
6. Export `-colorspace sRGB -strip -interlace Plane -quality 86` at 2880px wide to match
   the previous banner's dimensions.

## Assets referenced

- Style reference: any current `capell.app` marketing hero, e.g.
  `~/Sites/capell-app/public/images/marketing/hero-artwork/capell-home-workflow-artwork.webp`.
- Brand tokens pulled from `capell.app`'s live CSS at generation time: background
  `#071312`, primary `#066b5a`/`#34d399`, cream `#f5efe5`. Re-check these against the live
  site before reusing — they are not guaranteed stable given this project's rate of
  change (the earlier banner's navy `#001d3d` palette was itself already retired by the
  time this recipe was written).
