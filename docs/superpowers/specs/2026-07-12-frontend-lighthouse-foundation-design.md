# Frontend Lighthouse Foundation Design

## Goal

Strengthen Capell's shared public-render performance defaults so themes get correct LCP discovery, responsive image preloads, non-blocking font rendering, and conservative offscreen image loading without adding a new runtime or changing editor behaviour.

## Evidence and constraints

- Representative Lighthouse runs cover the live home, pricing, contact, and quick-start pages on mobile and desktop.
- The local `capell-ruby` Foundation demo cannot currently render because the companion Foundation theme still references the classic Theme Studio renderer API removed from Core. That pre-existing cross-repository mismatch is outside this performance diff.
- Raw Lighthouse reports remain outside git.
- Public HTML must not expose model identifiers, package metadata, editor state, or internal cache keys.

## Design

### LCP media identity

`BuildFrontendMediaHintsAction` will retain both the selected preload URL and the media's canonical URL. The canonical URL is internal render data used to identify the same media object when a Blade component selects a size-specific conversion. This removes the current false mismatch between a preloaded large conversion and an `<img>` that selects a medium conversion.

### Responsive preloads

The media hint will carry an optional `imagesrcset` and `imagesizes` value. Responsive Media Library sources are used when available; otherwise Capell's generated conversion widths are emitted. The head renders those attributes only when present, allowing the browser to preload the same candidate it will use for the viewport instead of always downloading the large fallback.

### Loading defaults

The shared media component will eagerly load only a known LCP image or a caller-requested eager image. Other images default to native lazy loading. Existing explicit caller choices continue to win. The logo component remains explicitly eager so changing the shared default does not delay above-the-fold branding.

### Fonts

Generated local `@font-face` rules will use `font-display: swap` so text is visible during font loading. Existing font family, style, weight, and sanitisation behaviour remains unchanged.

## Testing

- Action tests cover canonical identity and responsive conversion sources.
- head view tests cover responsive preload attributes and `font-display: swap`.
- media component tests cover LCP eager/high priority, non-LCP lazy defaults, and explicit eager overrides.
- Focused Frontend Pest tests run before package-wide verification.
- Lighthouse findings and local-package verification are reported separately because the local Foundation route blocker prevents a valid before/after browser comparison in this checkout.

## Non-goals

- Changing CDN, analytics, consent, or cache infrastructure.
- Reintroducing the removed classic Theme Studio pipeline.
- Reworking theme markup or visual design.
- Adding JavaScript or third-party dependencies.
