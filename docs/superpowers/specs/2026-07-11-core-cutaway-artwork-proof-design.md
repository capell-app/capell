# Capell Core Cutaway Artwork Proof Design

## Objective

Replace the Core README hero and Marketplace card with the first proof of a new Capell Foundation campaign. The proof establishes the campaign language before the other packages are redesigned: a deterministic architectural cutaway, a real Capell wordmark, deterministic copy and diagrams, and factual Core screenshots used as operational displays.

## Scope

This slice changes only the Core hero/card outputs and the artwork renderer needed to produce them. Existing Core screenshot captures and marketplace manifest paths remain unchanged. The former generated Core background is removed from the production path; generated backgrounds for other packages are left untouched until their own approved slices.

## Core Composition

The 2880×960 hero is a warm technical-paper drawing. A navy foundation cutaway forms seven named layers: Site, Language, Page, URL, Settings, Theme, and Extension. It has bridges, rails, portals, and small mechanical structures, but no generated lettering, logos, UI, or claims.

The navy Capell wordmark is sourced from `/Users/ben/Sites/capell-app/art/capell-logo.svg`, preserving its curved A and underline. Deterministic typography gives the package title `CORE`, the promise `The structure beneath every site`, and four numbered route stations: Define, Connect, Resolve, and Extend. Amber, blue, and emerald marks distinguish the journey phases.

The real `core-page-structure.png` and `core-settings-backed-configuration-dark.png` captures are cropped into large framed operational windows. Their UI is neither edited nor regenerated. The 800×500 card is separately laid out around one dominant foundation cutaway, one clearly legible page-hierarchy window, and the compact stages Define, Resolve, and Extend.

## Production Pipeline

A Node renderer produces SVG source from fixed Core layout data, embeds the source wordmark and screenshot files, then rasterizes the SVG at the exact output dimensions. An image export step creates stripped, sRGB, progressive JPEG output at the established paths:

- `packages/core/docs/assets/readme/hero.jpg`
- `packages/core/docs/assets/marketplace/extension-card.jpg`

No ImageMagick-only composition remains in the Core route. The renderer is deterministic: fixed coordinates, SVG shapes, fonts available to the project environment, and controlled screenshot crops. The source SVG may contain the semantic artwork labels; the JPEG only contains the resulting deterministic artwork and factual screenshots.

## Validation

- Inspect the hero at 2880×960 and the card at 800×500 and 400×250.
- Verify the actual Capell wordmark appears proportionally correctly in both outputs.
- Confirm progressive JPEG encoding, sRGB colourspace, exact dimensions, and repository-friendly file sizes.
- Confirm Core screenshot windows are legible and derived only from committed Core captures.
- Run the documentation screenshot gate and the focused Core manifest/asset tests that resolve README and `capell.json` paths.

## Follow-on Boundary

The Core proof is the review gate. Admin, Frontend, Installer, and Marketplace will use the same renderer only after the Core campaign language is accepted; their stories and screenshots are not changed in this slice.
