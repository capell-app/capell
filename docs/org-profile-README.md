<!--
  This file is a draft of the intended content of profile/README.md in the
  capell-app/.github repository. GitHub renders that file as the
  organisation's public front page (github.com/capell-app) — but only when
  the repository hosting it is public.

  The capell-app/.github repository already exists (created
  2026-05-26T16:41:09Z, verified via `gh api repos/capell-app/.github`) — it
  is not something Ben needs to create. It is currently **private**, which is
  the reason github.com/capell-app renders no public profile today: a private
  .github repo's profile/README.md does not render anywhere public, the same
  as if the repo didn't exist. It already contains its own profile/README.md
  (1,623 bytes, read via
  `gh api repos/capell-app/.github/contents/profile/README.md`), but that
  content is an internal maintainer repo map and contribution-flow doc (which
  repos are canonical, which are generated splits, where PRs get forwarded) —
  it is not a public marketing profile and was not written to be one.

  So the outward-facing action for Ben is not "create the repo". It is:
  (1) decide whether this draft replaces the existing internal
  profile/README.md outright, or the two get merged (e.g. move the internal
  repo-map content elsewhere first, such as CONTRIBUTING.md, so nothing is
  lost); then (2) make the repository public. See
  docs/github-repo-surface.md, section 8, for the exact sequence.

  Two links below were not found in any repo file and were inferred by
  analogy with the live capell.app site rather than sourced from this
  checkout: the X link (`https://x.com/capell_app`) and the Roadmap link
  (`https://capell.app/roadmap`). Both resolve (HTTP 200 as of 2026-07-29),
  but neither is confirmed against a repo source — treat both as needing
  Ben's confirmation before this draft is published anywhere.

  This draft is not published anywhere; it lives here in docs/ for review
  only.
-->

# Capell

![Fine-line engraved blueprint on deep navy showing Capell's foundation feeding two branching structures, with the Capell wordmark on the left](https://raw.githubusercontent.com/capell-app/capell/main/docs/images/capell-readme-hero.jpg)

**Capell is an open-source CMS for Laravel, built on Filament.**

Every page save appends a full-state revision: editors compare changes field by field, roll back, and roll forward without erasing history. Page types, layouts, URLs, and publishing rules are structured records in your Laravel app — managed in a Filament admin, rendered by your own frontend. And Capell is Composer packages, not a monolith: a free, MIT-licensed **Capell Foundation** plus verified extensions — search, SEO, publishing workflow, forms, themes, and more — whose migrations, data access, and removal paths you can inspect before they install.

Capell is not a hosted CMS and does not ship a public content-delivery API. Your pages render inside your Laravel application through Blade, Livewire, Inertia, Vue, or your own stack.

[capell.app](https://capell.app) · [Documentation](https://docs.capell.app) · [Live demo](https://capell.app/demo) · [Install instructions](https://github.com/capell-app/capell/blob/main/docs/getting-started/install.md) · [Roadmap](https://capell.app/roadmap) · [X](https://x.com/capell_app)

## Start here

Install it into an existing Laravel application with two commands:

```bash
composer require capell-app/installer
php artisan capell:install
```

See the [main repository](https://github.com/capell-app/capell) for the full README, quickstart, and contribution guide.

## The foundation packages

| Package     | Composer name                                                   | What it does                                                                               |
| ----------- | ---------------------------------------------------------------- | ------------------------------------------------------------------------------------------- |
| Core        | [`capell-app/core`](https://github.com/capell-app/core)               | Laravel CMS content, publishing, extension, install, and upgrade foundations for Capell.    |
| Admin       | [`capell-app/admin`](https://github.com/capell-app/admin)             | Filament administration, page editing, recovery, settings, and operations for Capell CMS.   |
| Frontend    | [`capell-app/frontend`](https://github.com/capell-app/frontend)       | Public routing, rendering, themes, assets, and cache-safe delivery for Capell CMS.           |
| Installer   | [`capell-app/installer`](https://github.com/capell-app/installer)     | Guided browser and CLI installation for Capell CMS on Laravel.                              |
| Marketplace | [`capell-app/marketplace`](https://github.com/capell-app/marketplace) | Extension marketplace browsing and acquisition for Capell CMS.                              |

Descriptions above are copied verbatim from each package's own `composer.json`.
