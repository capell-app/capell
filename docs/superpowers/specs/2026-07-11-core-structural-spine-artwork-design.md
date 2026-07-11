# Core Structural Spine Artwork Design

## Objective

Redesign the Core hero and marketplace card around one deliberate product story: Capell Core is the structural spine inside a Laravel application. The supporting outcome is that teams define structure once and use it to deliver distinctive sites without starting over.

The result must feel authored rather than decorated. Every visible architectural element must map to a Capell relationship or be removed.

## Primary Audience and Message

The primary reader is a technical CMS evaluator comparing Capell with other Laravel, hosted, headless, or hand-rolled approaches. The image must make these differences visible:

- Capell installs inside a Laravel application rather than operating as a separate runtime.
- Core, Admin, Frontend, and optional packages have explicit boundaries.
- Structure is defined once through reusable content and presentation contracts.
- The application team retains ownership of public frontend delivery.

The agency outcome is secondary: the same governed structure can produce multiple distinctive sites and reduce long-term rebuild and maintenance work.

## Hero Composition

The 2880×960 hero uses one bounded Laravel application frame as its architectural section. Within it:

1. A central navy Core spine carries three explicit structural planes:
   - Site context: Site + Language.
   - Content and addressing: Page + URL.
   - Configuration and presentation contracts: Settings + Theme.
2. Extension points appear as visible perimeter sockets that can connect to multiple planes. Extension is not the final step in a linear pipeline.
3. A separate Admin room connects to Core through one controlled interface.
4. An application-owned Frontend branch remains visually separate from Admin and terminates in two or three distinctive site elevations built on the same navy chassis.
5. A compact Composer package module docks into the Laravel application boundary.

One restrained journey passes through the composition: Define → Connect → Resolve → Extend. The journey and structural planes are one system, not competing taxonomies.

The deterministic title is a continuous `CAPELL CORE` lockup using the real Capell wordmark and a light high-contrast serif `CORE` on the same baseline. The promise remains `The structure beneath every site` unless the composition proves that `Define structure once. Deliver distinctive sites.` is more direct at review size.

## Marketplace Card

The 800×500 card is independently composed. It contains the lockup, one central Core spine, one Admin connection, one application-owned Frontend connection, one optional package socket, and two visibly distinct site outputs. Its compact journey is Define → Reuse → Extend.

At 400×250, the four relationships—Laravel boundary, Core spine, Admin, and application-owned Frontend—must remain obvious even if secondary labels are unreadable.

## Generated and Deterministic Layers

Nano Banana generates only a text-free engraved architectural base: warm paper, navy drafting ink, bounded chassis, three structural planes, controlled joints, and restrained depth. It must not generate readable text, logos, UI, claims, badges, browser chrome, gears, pipes, pistons, fans, conveyors, dark underground voids, empty machinery bays, or arbitrary construction marks.

Deterministic composition owns:

- the real Capell wordmark and `CORE` typography;
- all labels, claims, journey stages, and arrows;
- the Laravel application boundary and explicit interface labels;
- pastel semantic diagrams derived from factual Capell structure;
- the distinct site-output elevations.

Colour is semantic:

- navy: governed structure and boundaries;
- powder blue: reusable system and connection;
- soft emerald: safe editable variation and frontend output;
- amber: progression and extension seams.

Coral is removed unless a later review gives it a unique, necessary meaning.

## Removal List

Remove the current factory imagery, generic browser panels, traffic-light dots, toggles, status pills, dashed decorative arrow, seven-stop linear rail, overlapping journeys, floating displays, and fine detail that collapses into noise at marketplace size.

## Review Loop

No render is accepted after a single visual pass. Each meaningful candidate must be reviewed at 2880×960, 800×500, and 400×250 by these perspectives:

1. Competitive evaluator: can the image explain why Capell differs from a hosted CMS, headless service, or hand-rolled Filament admin?
2. Critical Laravel developer: are boundaries and extension seams credible, factual, and free from opaque-runtime implications?
3. Agency buyer: does the image communicate repeatability, controlled flexibility, and year-two maintainability?
4. Visual-quality reviewer: is hierarchy calm, intentional, premium, and readable without arbitrary detail?

Review findings are prioritized. A candidate is regenerated or recomposed when any reviewer identifies a misleading metaphor, arbitrary decoration, competing hierarchy, unsupported product claim, or marketplace-size failure. The loop ends only when all four reviews explicitly approve the same candidate with no important issues.

## Validation

- Generated pixels contain no readable text, logo, UI, claims, badges, or watermark.
- The real wordmark retains its curved A and underline.
- Hero and card are progressive sRGB JPEGs at exact dimensions.
- The card passes a dedicated 400×250 inspection.
- Existing README and manifest paths remain valid.
- The artwork contract, documentation screenshot gate, and focused Core manifest/marketplace tests pass.
- Repository-wide failures unrelated to artwork are reported separately and are not hidden.
