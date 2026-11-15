<!--
  This file is a draft of the intended content of profile/README.md in the
  capell-app/.github repository. GitHub renders that file as the
  organisation's public front page (github.com/capell-app) — but only when
  the repository hosting it is public.

  The capell-app/.github repository is public and this content is LIVE: it
  was published to capell-app/.github at profile/README.md on 2026-07-30 and
  renders today at github.com/capell-app (verified 2026-08-06). Earlier
  revisions of this comment described the repo as private and the draft as
  unpublished — that is no longer true.

  Because it is live, treat this file as the editable source and keep the two
  in sync: an edit here is not visible to anyone until it is copied to
  capell-app/.github. See docs/github-repo-surface.md, section 8.

  This copy matches the live file as of 2026-11-15. Make changes here first,
  then copy the content below this comment to capell-app/.github.
-->

# Capell

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/capell-app/capell/main/docs/images/capell-readme-hero.jpg">
  <img alt="The Capell wordmark on a faint drafting grid, above the lines “Extensible Laravel CMS” and “Strict coding standards. Extend it, never fork it.”" src="https://raw.githubusercontent.com/capell-app/capell/main/docs/images/capell-readme-hero-light.jpg">
</picture>

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
