---
name: capell-site-building
description: 'Builds a website in a Capell application from what Capell already provides: site settings and branding, themes, page types, layouts, Layout Builder widgets, Block Library blocks, Marketplace packages and page content. Use when asked to build, restyle, extend or fill a Capell site, before writing any one-off Blade, HTML or CSS.'
---

# Building a site with Capell

Capell sites are assembled from reusable, editable parts. Reach for them in this order and write one-off code only when none fits. Each step below names the tool to use and how to check the result.

## Orient first

```bash
php artisan capell:doctor
composer show 'capell-app/*'
php artisan list --raw | grep '^capell'
```

- Read the installed package's README under `vendor/capell-app/<package>/` when a skill does not cover it. Never edit `vendor/`.
- If Capell is not installed, run `php artisan capell:install`. Without a terminal, pass every answer: `--no-interaction --preset=<suite> --url=<url> --name=<admin name> --email=<admin email> --password=<password>`.

## 1. Settings and branding

- The site name, address, languages, logo and theme colours are site and theme data, edited in the admin panel under `/admin` (Sites, Themes and Settings).
- Read and change registered settings from the command line with `php artisan capell:settings:get [group]` and `php artisan capell:settings:set <group.name> <value>`. Values are checked against the setting's type.
- A SiteSpec carries `site.businessName` and `theme.colors`. Theme settings are merged into an existing site when the spec's theme key matches the site's theme; other site fields of an existing site are not changed by an import. Read `capell` skill `references/site-spec.md`.
- Never hard-code a colour, logo or business name in Blade: the theme reads them from settings.

## 2. Theme

- Pick an installed theme first: the Foundation theme (`default`) ships with Capell, and more themes are Marketplace packages.
- A site uses one theme. Switch it with `php artisan capell:theme:activate <key>` (add `--site=<id>` when there is more than one site, and `--default` to make it the theme new sites start with), or in the admin panel under Themes.
- For a new look, create a child theme: `php artisan capell:make:theme <key> --local --extends=default`, then follow the generated README to require it with Composer, check it with `php artisan capell:themes:validate <key>` and activate it.
- Decide what is theme markup and what is editable data with the `capell-theme-scale` skill, and build themes with `capell-theme-development`.

## 3. Page types

- A page type is a page blueprint editors choose when they add a page. Create one with `php artisan capell:make:page-type <key> --name="<Name>"` (`--dry-run` previews it), then add its fields in the admin panel under Blueprints or with a page schema extender (`PageSchemaExtender::TAG`), never by publishing Core schemas.
- Use one page type for every page that shares fields, such as services or case studies.
- A model of your own that should carry blueprints (not a kind of page) is a blueprint subject: `php artisan capell:make:blueprint <Name>`. Read `capell` skill `references/extending.md`.

## 4. Layouts

- A layout is a set of named containers (for example header, main, sidebar, footer) that Layout Builder widgets are placed into. Create one with `php artisan capell:make:layout <key> --name="<Name>" --from=<existing layout key>` to start from another layout's containers, place widgets in the admin panel under Layouts, and assign the layout to pages instead of copying markup between pages.
- When seeding layouts in code, use Layout Builder Actions such as `AddWidgetToLayoutContainerAction`. Read `capell-cms-development` `references/layout-builder.md`.

## 5. Widgets and blocks

- Use the Layout Builder widgets and Block Library blocks already installed before creating a widget. List them with `php artisan capell:widgets:list --json` and `php artisan capell:blocks:list --json`. Editors can then change the copy, images and settings without a deploy.
- A new widget gets a blueprint for its editable fields, a view that renders hydrated data, and a test: `php artisan capell:make:widget <Name>` writes the view and the test and prints the seeder snippet that registers it. Never use the removed `CapellCore::registerWidget()`.
- Public Blade receives hydrated render data: no database queries or lazy loading in views, and no editor IDs, field paths or permissions in public HTML.

## 6. Packages

- Add a feature from the Marketplace instead of building it: `composer require capell-app/<package>`, then `php artisan capell:extension-install capell-app/<package>`. Run `php artisan boost:install` afterwards so its guidelines and skills reach your agent.
- Paid packages need authenticated Composer access after purchase.

## 7. Content

- Describe pages, navigation, theme colours and media in a SiteSpec JSON file. Preview with `php artisan capell:site-spec-import <file> --plan --site=<id>` and apply without `--plan`. Export the current site with `php artisan capell:site-spec-export <site-id> --output=site-spec.json` and keep it in Git.
- Pages are created as drafts unless the spec is public; publish deliberately with `--publish --user=<editor email>`.
- Seed minimal editable content. Never store designed page markup as content.

## Check the result

```bash
php artisan capell:doctor
php artisan test --compact
```

Open the public page and the admin editor for each page you changed, and add a feature test that requests the page and asserts the content renders.
