# Admin onboarding and copy design

## Purpose

Make Capell's administration area understandable to a new, non-technical editor or site manager without weakening the advanced controls needed by administrators and developers.

This pass combines two related improvements:

1. a short, replayable welcome tour on the main dashboard; and
2. clearer labels, helper text, page headings, table descriptions, and empty states across Capell's core admin resources.

The work improves guidance only. It does not change content models, publishing behaviour, permissions, navigation structure, or public rendering.

## Audience and principles

The primary audience is a new editor or site manager who needs to understand where content lives and how to publish it safely. Administrator and developer surfaces remain available, but the first-run experience does not lead with implementation concepts.

All guidance follows these rules:

- use plain English and sentence case;
- describe the outcome of a choice, not the internal implementation;
- explain unfamiliar CMS terms on first use;
- state material consequences, especially for publishing, URLs, themes, and deletion;
- do not add helper text to self-explanatory fields such as a person's name or email address;
- keep every user-facing string in Capell's translation files;
- only show actions and tour steps that the current user can access.

## First-dashboard experience

### Welcome callout

On a user's first visit to a fully installed Capell dashboard, show a compact welcome callout with two actions:

- **Take the two-minute tour** starts the walkthrough.
- **Not now** closes the callout.

Either choice dismisses the versioned callout for that authenticated user through the existing `dismissed_hints` mechanism. The first version uses the key `welcome.tour.v1`. Versioning allows Capell to introduce a materially different walkthrough later without resetting unrelated hints.

The callout must not appear while Capell is uninstalled, while required runtime tables are missing, or when no site exists. It must not block dashboard use.

### Tour content

The core walkthrough contains no more than five concise steps:

1. **Dashboard** — explains that this is the overview of sites, content status, and recent work.
2. **Sites** — explains that a site groups its domains, languages, branding, and pages.
3. **Pages** — identifies the main content workspace and distinguishes draft, scheduled, and published content.
4. **Media** — explains where reusable images and files are managed.
5. **Publishing safely** — points users toward previewing, saving drafts, and publishing when ready.

The opening step may be modal-style. Highlighted steps should target stable Capell-owned hooks or attributes rather than presentation classes or translated text. A missing target must not prevent the remaining steps from running.

Tour steps are assembled from the existing `WelcomeTourStepData` registry. Core registers the default steps, and packages may continue contributing ordered, permission-aware steps through `AdminBridgeRegistrar`. Package steps must not bypass the current visibility callback.

### Replay

The installed dashboard always exposes a **Take the tour** header action to users who can access the relevant dashboard. Replaying the tour does not clear or alter dismissal history.

## Tour integration

Capell completes its existing Filament Tour integration rather than adding a second walkthrough library:

- declare `jibaymcs/filament-tour` as a runtime dependency of `capell-app/admin` and align the monorepo root dependency;
- register `FilamentTourPlugin` on Capell's admin panel;
- add a small Capell-owned adapter that converts visible `WelcomeTourStepData` records into Filament Tour `Step` objects;
- keep tour construction out of `CapellDashboard` so the page only delegates to the adapter and dispatches start/replay events;
- use the existing `DismissHintAction` for per-user callout state;
- keep local browser history inside the third-party tour as a secondary safeguard, not the source of truth for whether the callout is shown.

If the third-party package cannot safely skip a missing selector, the adapter will omit a targeted step when its associated admin surface is unavailable. Capell will not fork vendor code for this feature.

## Admin copy audit

The copy audit covers Capell-owned core admin resources and pages. It does not rewrite copy supplied by companion packages.

### Priority surfaces

The editor journey receives the deepest pass:

- Dashboard
- Sites and site domains
- Pages and page URLs
- Media
- Layouts
- Languages
- Themes
- Redirects

Administrative resources also receive clear page and table context where missing:

- Users and roles
- Blueprints
- Block templates
- Activity log

### Resource pages and tables

Every listed resource page should have an action-led heading or subheading that answers what the user can do there. Every primary table should have a short description or an informative empty state that explains what records represent and, when useful, the next action.

Examples of the intended tone:

- Pages: "Create, organise, preview, and publish the content shown on your sites."
- Sites: "Manage each website's domains, languages, branding, and content."
- Redirects: "Send visitors and search engines from an old URL to its current destination."

Descriptions must not promise behaviour that the underlying resource does not provide.

### Forms

Audit visible labels and helper text in the listed resources, concentrating on fields whose effect is not obvious. In particular:

- clarify the difference between an internal name and public-facing text;
- explain URL paths, default domains, publication dates, and visibility;
- explain relationships among sites, layouts, themes, languages, and pages;
- translate technical terms such as blueprint or canonical URL into task-focused language while retaining the established domain term where necessary;
- make destructive or wide-scope settings explicit;
- remove duplicated helper text that merely repeats the label.

Existing configurator and extension seams remain unchanged. Copy is updated at the translation key or component definition that owns the surface; generated or package-authored content is not hand-edited.

## Accessibility and responsive behaviour

The callout and tour must be keyboard operable, expose meaningful button labels, respect the existing light and dark themes, and remain dismissible. It must not trap users in an uncloseable sequence. On viewports where a target is unavailable, the tour should fall back to an untargeted explanation or omit that step.

## Failure behaviour

- Unauthenticated users never receive tour state or callouts.
- A missing `dismissed_hints` column or incomplete installation follows the existing not-installed dashboard path and does not attempt to render the callout.
- A user without permission for a resource does not receive its step.
- A missing DOM target does not break the dashboard or prevent dismissal.
- Failure to load the tour JavaScript leaves the dashboard usable and the replay action harmless.

## Verification

Focused automated coverage will prove:

- core and package-contributed steps are ordered and visibility-filtered;
- the adapter maps translated titles, descriptions, selectors, and icons correctly;
- the welcome callout is shown only for eligible authenticated users;
- starting or dismissing the callout stores `welcome.tour.v1` once per user;
- the replay action remains available after dismissal;
- the panel registers the runtime tour plugin;
- representative editor and administrator resources expose the new translated headings, table descriptions, empty states, labels, and helpers;
- translation keys referenced by the changed components exist.

Browser verification will cover first display, start, completion, dismissal, replay, navigation targets, responsive fallback, dark mode, and the absence of console or backend errors.

## Out of scope

- a progress-tracking onboarding centre or checklist;
- changing installation or authentication flows;
- changing roles, permissions, publishing rules, or data models;
- redesigning the sidebar or dashboard layout;
- rewriting companion-package admin copy;
- translating the new English source strings into additional locales;
- forking or substantially modifying the third-party tour package.

## Completion criteria

The work is complete when a newly authenticated editor can understand the core Capell content journey from the dashboard, dismiss or replay the walkthrough, and encounter clear task-focused guidance throughout the core admin resources, with focused automated tests and browser evidence confirming that existing admin behaviour remains intact.
