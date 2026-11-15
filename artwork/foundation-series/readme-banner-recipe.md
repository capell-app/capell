# Root README banner recipe

The root [README](../../README.md) shows one of two banners, picked by the reader's GitHub
theme through a `<picture>` element:

- `docs/images/capell-readme-hero.jpg`: dark, also used by the organisation profile;
- `docs/images/capell-readme-hero-light.jpg`: light.

Both are 2880×1200 progressive JPEGs rendered from
[readme-banner/banner.html](readme-banner/banner.html). No image model is involved: the
wordmark is the canonical [capell-logo.svg](capell-logo.svg) (navy on light, recoloured
cream on dark) and the type is Sora from `readme-banner/fonts`.

## Rendering

```bash
artwork/foundation-series/readme-banner/render.sh
```

The script needs Google Chrome (override the path with `CHROME`) and ImageMagick. It writes
both JPEGs into `docs/images/`.

## Layout notes

- Tagline lines are set in `banner.html`: the first line says what Capell is, the second
  gives the evidence. Keep the second line close to the wordmark's width so both stay
  readable at phone width (about 360px).
- The drafting grid is offset so one horizontal line runs along the wordmark's underline
  and one vertical line meets its left edge. Recalculate `background-position` if the
  wordmark's size or position changes.
- The teal glow behind the wordmark is weaker on light (`--glow: 5%`) than on dark (16%).
