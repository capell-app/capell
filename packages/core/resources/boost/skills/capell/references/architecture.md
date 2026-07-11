# Capell Architecture Reference

## Core Database Schema

### Primary Tables (core package, 18 migrations)

| Table               | Purpose                            | Key Columns                                                                     |
| ------------------- | ---------------------------------- | ------------------------------------------------------------------------------- |
| `pages`             | Hierarchical pages (nested set)    | `site_id`, `blueprint_id`, `slug`, `status`, `parent_id`, `lft`, `rgt`, `depth` |
| `sites`             | Multi-site management              | `name`, `domain`, `default_language_id`, `theme_id`                             |
| `languages`         | Language definitions               | `code`, `locale`, `name`, `site_id`, `is_default`                               |
| `blueprints`        | Content/page/element type registry | `name`, `class`, `type` (PageTypeEnum)                                          |
| `translations`      | Translatable content storage       | `translatable_type`, `translatable_id`, `locale`, `key`, `value`                |
| `page_urls`         | Per-language SEO URLs              | `page_id`, `language_id`, `url`, `slug`                                         |
| `navigations`       | Menu structures                    | `site_id`, `language_id`, `name`, `items` (JSON)                                |
| `layouts`           | Page layout definitions            | `site_id`, `name`, `template`                                                   |
| `site_domains`      | Domain routing                     | `site_id`, `domain`, `is_primary`                                               |
| `themes`            | Theme management                   | `name`, `path`                                                                  |
| `plugins`           | Plugin registry                    | `name`, `version`, `config`                                                     |
| `audits`            | Activity logs (Spatie)             | `user_type`, `user_id`, `event`, `auditable_type`, `auditable_id`               |
| `asset_attachments` | Media asset relationships          | `model_type`, `model_id`, `media_id`, `type`                                    |
| `media`             | Spatie media library               | `model_type`, `model_id`, `collection_name`, `file_name`                        |

`blueprints` is the current registry table for reusable page, site, theme, section, widget, and content-block behaviour. The legacy `Type` model and `type` relation remain as compatibility aliases over `Blueprint` and `blueprint`; new code and docs should use Blueprint terminology unless they are documenting that compatibility layer.

### Add-on Package Tables

| Package        | Tables                                          |
| -------------- | ----------------------------------------------- |
| Address        | `countries`, `addresses`                        |
| AIOrchestrator | `ai_generation_histories`                       |
| Blog           | `articles` (with tags via Spatie), `taggables`  |
| Layout         | `contents`, `elements`, `layout_element_assets` |

## Core Models

### Page

- Uses `kalnoy/nestedset` for hierarchical structure
- Has drafts/publishing workflow
- Polymorph to `PageUrl` for multi-language URLs
- Belongs to `Site`, `Layout`, `Blueprint`
- Has `Translations` via Spatie Translatable

### Site

- Has many `Languages` (pivot)
- Has many `Pages`
- Has one default `Language`
- Has one `Theme`
- Has many `SiteDomains`

### Blueprint (Content Behaviour Registry)

- Enum `PageTypeEnum` defines page vs content vs element types
- Auto-discovered from registered namespaces
- Each blueprint defines editor schema, rendering, cache, listing, sitemap, and permission behaviour
- Interceptors override special blueprints such as home, 404, maintenance, and system pages

## Service Providers Bootstrap Order

1. **CapellServiceProvider** (Core)
    - Enforces morph map requirement
    - Registers 18+ migrations
    - Registers core config (`capell.php`)
    - Registers gate/policy guesser
    - Registers translation event listeners
    - Registers 9 core artisan commands
    - Registers Blueprint macros for migrations

2. **AdminServiceProvider** (Admin)
    - Registers 12 Filament resources
    - Registers 11 admin artisan commands
    - Sets up schema extender resolvers
    - Registers event subscribers
    - Initializes page blueprint interceptors (home, 404, maintenance, system)
    - Sets up Filament macros
    - Registers admin config (`capell-admin.php`)

3. **FrontendServiceProvider** (Frontend)
    - Registers 5 frontend artisan commands
    - Sets up routes (web.php)
    - Configures HTML caching middleware
    - Sets up HTML minification
    - Registers Livewire components
    - Configures Tailwind asset registry
    - Registers navigation helpers
    - Registers frontend config (`capell-frontend.php`)

4. **FrontendAuthoringServiceProvider** (optional add-on)
    - Registers the shared beacon route name `capell-frontend.beacon`
    - Returns authoring bootstrap scripts only after admin access is confirmed
    - Opens signed single-field Filament editors from admin-only hover controls
    - Clears cached URLs recorded in `cached_model_urls` after edits

## Key Interfaces & Contracts

- `HasSchema` — Settings schemas must implement `make(Schema $schema): array`
- `EventSubscriber` — For registering model/event listeners
- `BladeComponentResolver` — For custom frontend Blade component resolution

## Middleware Chain (Frontend Requests)

Every frontend request goes through:

1. `frontend.resolve` — Resolves site from domain/path
2. `frontend.cache` — Checks HTML cache, serves static file if hit
3. `frontend.model_events` — Registers model observers for cache invalidation

Frontend authoring must not add markers to this rendered HTML. If `capell-app/frontend-authoring` is installed, the page loads normally first; then the beacon may decorate matching selectors for admins only.

## Event System

### AdminEventRegistry

Allows registering callbacks for admin events. Packages use this to hook into page save, delete, etc. via `CapellAdmin::on('page.saved', fn() => ...)`.

### EventSubscriber Interface

Packages implement this to subscribe to model lifecycle events (created/updated/deleted) for cache invalidation, sitemap regeneration, etc.

## Filament Resources (Admin Panel)

12 core resources in `packages/admin/src/Filament/Resources/`:

- `PageResource` — Page CRUD with blueprint-based schema fields
- `SitesResource` — Multi-site management
- `LanguagesResource` — Language definitions
- `BlueprintResource` — Blueprint registry management
- `ThemesResource` — Theme management
- `NavigationsResource` — Menu builder
- `LayoutsResource` — Layout definitions
- `MediaResource` — Media library (Spatie)
- `PageUrlsResource` — URL management
- `UsersResource` — User management

## Dependencies (Key Packages)

| Package                        | Use                 |
| ------------------------------ | ------------------- |
| `filament/filament`            | Admin UI framework  |
| `spatie/laravel-activitylog`   | Audit trail         |
| `spatie/laravel-medialibrary`  | Media management    |
| `spatie/laravel-settings`      | Type-safe settings  |
| `spatie/laravel-tags`          | Tagging (Blog)      |
| `spatie/laravel-translatable`  | Model translation   |
| `lorisleiva/laravel-actions`   | Action classes      |
| `livewire/livewire`            | Frontend components |
| `kalnoy/nestedset`             | Page hierarchy      |
| `icamys/php-sitemap-generator` | Sitemap generation  |
| `torann/geoip`                 | IP geolocation      |
| `pestphp/pest`                 | Testing framework   |
