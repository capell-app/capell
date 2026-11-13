# Skills for building with Capell

Capell ships agent skills with its packages, so an AI coding assistant working in your application knows how Capell is put together. Each skill loads when your task matches its description.

## Install the skills

Capell skills install through [Laravel Boost](https://laravel.com/docs/boost). Run the installer with developer tooling enabled, or run Boost yourself:

```bash
php artisan capell:install --developer-tooling
php artisan boost:install --skills
```

Boost lists the installed Capell packages in `boost.json` and copies each package's skills into your application's agent directory. Run `php artisan boost:update` after a package upgrade to refresh them.

## Choose a skill

| I need to... | Skill |
| --- | --- |
| Understand Capell's models, rendering and extension points | `capell` |
| Import a SiteSpec, install a demo or manage Marketplace extensions | `capell` |
| Convert a Laravel or Blade site into CMS content, or debug seeded content | `capell-cms-development` |
| Work with a specific package, such as Layout Builder, Blog or SEO Suite | The skill that installs with that package |

Package skills are named after their package, for example `layout-builder` or `capell-seo-suite-development`. They are installed only when the package is.

## Related guides

- [AI-ready Capell](ai-ready.md) for the context and safety boundary Capell gives AI features.
- [How Capell works](how-capell-works.md) for the page, URL, translation and layout model the skills assume.
