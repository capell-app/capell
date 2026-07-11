# Credits

![Capell Credits screenshot](../images/capell-readme-banner.jpg)

Capell is built on a careful mix of open-source frameworks, packages, and developer tools. This is not a full dependency manifest; Composer and npm already do that job better. This page highlights the projects that have shaped Capell's architecture, admin experience, frontend runtime, and release workflow.

## Major Foundations

| Project                                                                                        | Why it matters to Capell                                                                                                                                                                                                                                                                          |
| ---------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [Laravel](https://laravel.com)                                                                 | Laravel gives Capell the application foundation: routing, Eloquent models, queues, events, caching, authorization, service providers, testing helpers, and the wider Composer ecosystem. Capell stays Laravel-native because those tools are already excellent and familiar to serious PHP teams. |
| [Filament](https://filamentphp.com)                                                            | Filament is the reason Capell Admin can feel fast to build with and polished to use. Its resources, pages, widgets, actions, forms, tables, icons, and panel system give Capell a strong admin surface without burying developers under custom UI plumbing.                                       |
| [Livewire](https://livewire.laravel.com)                                                       | Livewire keeps interactive admin and frontend behaviour close to Laravel and Blade. That helps Capell ship dynamic interfaces without forcing every package into a heavy JavaScript application model.                                                                                            |
| [Livewire Blaze](https://github.com/livewire/blaze)                                            | Blaze helps Capell render Blade components with less runtime overhead. It fits the project well because Capell cares about frontend speed, cache-friendly pages, and keeping public rendering lean.                                                                                               |
| [GitHub](https://github.com) and the [Capell repository](https://github.com/capell-app/capell) | GitHub hosts Capell's source, issue workflow, pull requests, code review, and automation. It gives the project a dependable place to collaborate, test changes, and keep the package ecosystem moving in public.                                                                                  |

## Architecture And Package Shape

| Project                                                                         | Why it matters to Capell                                                                                                                                                                                                             |
| ------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| [Laravel Actions](https://laravelactions.com)                                   | Laravel Actions gives Capell a clean home for business behaviour. Actions keep controllers, Filament pages, commands, and Livewire components thin, which makes package features easier to test and easier to move between surfaces. |
| [Spatie Laravel Data](https://spatie.be/docs/laravel-data)                      | Laravel Data gives Capell typed objects at package boundaries. It keeps request state, form state, JSON columns, and API-style output explicit instead of passing loose arrays through the system.                                   |
| [Spatie Laravel Package Tools](https://github.com/spatie/laravel-package-tools) | Package Tools smooths out service provider setup for Capell packages. It keeps package registration predictable, which matters when the core product and add-ons all need to install cleanly.                                        |
| [Symfony Components](https://symfony.com/components)                            | Symfony's filesystem and process components give Capell solid low-level tools for installer flows, command execution, and file operations without inventing project-specific wrappers for solved problems.                           |

## Admin And Editorial Experience

| Project                                                                        | Why it matters to Capell                                                                                                                                                                                           |
| ------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| [Filament Shield](https://github.com/bezhanSalleh/filament-shield)             | Shield brings practical role and permission management into the Filament panel. Capell builds on that instead of pretending every CMS needs a custom permission UI from scratch.                                   |
| [Spatie Laravel Permission](https://spatie.be/docs/laravel-permission)         | Laravel Permission provides the role and permission model underneath Capell's admin access rules. It is battle-tested and clear, which is exactly what access control needs to be.                                 |
| [Spatie Laravel Activitylog](https://spatie.be/docs/laravel-activitylog)       | Activitylog helps Capell record meaningful changes around content, users, and admin operations. That audit trail is useful for editors and vital when a site needs to explain what changed.                        |
| [Spatie Laravel Medialibrary](https://spatie.be/docs/laravel-medialibrary)     | Medialibrary gives Capell a dependable default media backend with collections, conversions, and model attachment support. It lets Capell treat media as first-class content instead of scattered uploads.          |
| [AWCodes Curator](https://github.com/awcodes/filament-curator)                 | Curator is the preferred richer media workflow for projects that want a dedicated Filament media library and crop-friendly editor experience. Capell keeps media contracts flexible so teams can choose that path. |
| [TinyEditor for Filament](https://github.com/amidesfahani/filament-tinyeditor) | TinyEditor gives Capell a familiar rich-text editing surface inside Filament forms. It keeps everyday editor work comfortable without making text fields feel like a separate product.                             |
| [Filament Tour by JibayMcs](https://github.com/jibaymcs/filament-tour)         | Filament Tour, powered by [Driver.js](https://driverjs.com), gives Capell Admin a guided welcome tour so new editors can discover the dashboard, navigation, and key tools without custom tour plumbing.           |

## Frontend, Performance, And Authoring

| Project                                                                   | Why it matters to Capell                                                                                                                                                                                |
| ------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [Tailwind CSS](https://tailwindcss.com)                                   | Tailwind gives Capell and its packages a shared styling language. The frontend asset registry can gather package sources and imports because Tailwind works well with component-driven views.           |
| [Vite](https://vite.dev)                                                  | Vite keeps local frontend builds quick and modern. Capell uses it through Laravel's asset pipeline so package CSS and JavaScript can be compiled without a slow custom toolchain.                       |
| [Alpine.js](https://alpinejs.dev)                                         | Alpine handles small interactive behaviour where a full JavaScript app would be unnecessary. That fits Capell's preference for fast pages, Blade-first rendering, and modest frontend weight.           |
| [Page Cache by Joseph Silber](https://github.com/JosephSilber/page-cache) | Page Cache is one of the ideas behind Capell's static response strategy. It shows how Laravel pages can be served quickly when the content does not need to boot the full application on every request. |

## Testing And Code Quality

| Project                                                                             | Why it matters to Capell                                                                                                                                                                                 |
| ----------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [Pest](https://pestphp.com)                                                         | Pest keeps Capell's test suite readable. Its concise syntax makes action tests, package tests, and architecture checks easier to write and maintain.                                                     |
| [Orchestra Testbench](https://packages.tools/testbench)                             | Testbench lets Capell packages run inside a real Laravel application during tests. That catches provider, migration, configuration, and integration problems that plain unit tests would miss.           |
| [PHPStan](https://phpstan.org) and [Larastan](https://github.com/larastan/larastan) | PHPStan and Larastan help keep Capell honest about types, Laravel magic, and edge cases. They are a big part of keeping a package-based CMS maintainable over time.                                      |
| [Laravel Pint](https://laravel.com/docs/pint)                                       | Pint keeps formatting boring in the best way. Capell can focus reviews on behaviour and architecture because style is handled consistently.                                                              |
| [Rector](https://getrector.com)                                                     | Rector helps Capell apply safe mechanical improvements as PHP and Laravel evolve. That matters for a CMS that needs to stay upgradeable without turning maintenance into hand-editing hundreds of files. |

## Community And Ecosystem

Capell also benefits from the Laravel, Filament, Livewire, Spatie, Tailwind, Pest, and PHP communities around these tools. Their documentation, examples, issue threads, release notes, and package maintainers save Capell from solving every problem alone.

When a package becomes central to Capell's public behaviour, document it near the feature it supports and keep this page focused on the bigger foundations.
