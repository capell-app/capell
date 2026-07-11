# Foundation artwork sources

The Core artwork is the first proof of the architectural-cutaway Foundation campaign. It is rendered deterministically from SVG and real product captures; its structural illustration, wordmark, typography, labels, journey rail, and annotations are all controlled source artwork.

![Capell Core architectural foundation cutaway](../../packages/core/docs/assets/readme/hero.jpg)

The Core renderer uses:

- `capell-logo.svg`, vendored from the real Capell wordmark source;
- `packages/core/docs/images/screenshots/core-page-structure.png`;
- `packages/core/docs/images/screenshots/core-settings-backed-configuration-dark.png`.

Rebuild and verify the Core proof with:

```bash
node artwork/foundation-series/render-core.js
node artwork/foundation-series/verify-core.js
```

The output is a separately composed 2880×960 README hero and 800×500 marketplace card. Both are stripped, progressive sRGB JPEGs. The renderer uses `rsvg-convert` for SVG rasterization and ImageMagick only for the final JPEG export.

The remaining Admin, Frontend, Installer, and Marketplace assets stay on the previous atmospheric renderer until the Core campaign language is reviewed. Their generated backgrounds remain temporary inputs for that legacy path:

- **Admin:** a strong vertical navy architectural spine with orderly shelves and open work planes.
- **Frontend:** multiple request lanes passing through translucent gates into a bright right-hand destination.
- **Installer:** a calm stepped route through checkpoint frames into an open final platform.
- **Marketplace:** a catalogue field, central evaluation plinth, and navy track toward an amber queue zone.

Run `bash artwork/foundation-series/render.sh` from the repository root to rebuild the Core proof plus the eight legacy package assets.
