---
name: capell-theme-scale
description: 'Decides what belongs in a Capell theme and what belongs in editable data, across theme settings, page and widget blueprints and Layout Builder assets. Use when creating or changing a Capell theme, theme settings, a page or widget blueprint, Layout Builder assets, or when deciding whether a value is markup or content.'
---

# Capell theme scale

Use this with `capell-theme-development` when you build a theme, and with `capell-site-building` when you decide where a value lives.

## Theme tiers

- Foundation: `capell-app/theme-foundation`, theme key `default`. It owns the shared runtime, the default renderer, Tailwind assets, media URLs, Blade conventions and the base Layout Builder areas. Start every theme from it.
- Child themes extend Foundation (`extends: "default"` in `capell.json`) and change only their own sections, presets and assets. First-party child themes are listed in the Capell Marketplace.

## Ownership rule

If a value changes per site, page, widget, block, locale or editor decision, it belongs in Capell data:

| Value                                        | Where it lives                      |
| -------------------------------------------- | ----------------------------------- |
| Colours, fonts, logo, spacing presets        | Theme settings and presets          |
| Fields every page of a kind shares           | Page blueprint or page type schema  |
| A widget's editable copy, images and options | Widget blueprint and widget content |
| Page-specific or shared section content      | Layout Builder widget assets        |
| Placement, container and block options       | Block and container meta            |

If a value is only the HTML, CSS or component shape that displays that data, it belongs in the theme.

## Sections

- Build the standard sections first: navigation, hero, features, proof, content listing, call to action and footer.
- Add a section that depends on an optional package only with a safe fallback when that package is absent.

## Public output rules

- Public Blade must not query the database or lazy-load relationships; it receives hydrated render data.
- Public output must not expose authoring controls, signed URLs, permissions, admin routes, field paths, schema labels, model IDs, package names or editor metadata.
- Seed minimal editable content and block configuration, never designed page markup.
- Themes render public presentation only. Authoring behaviour stays in the authoring packages.

## Shared behaviour

- Put behaviour every theme needs in Foundation or the Theme Studio runtime, and composition behaviour in Layout Builder Actions, payload contributors or layout areas.
- Touch a child theme only for its own markup, presets or renderers. Do not copy identical logic across theme providers.

## Verification

Test the theme definition and every preset it advertises, the manifest, renderer registration, optional-package fallbacks, and public rendering on a seeded route, asserting both the expected content and the absence of authoring data.
