# Capell CMS Seeding Reference

## Core Tables

| Table             | Purpose                                                                                                                                                            |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `sites`           | Publishing surface. Points to `blueprint_id`, `theme_id` and `language_id`; has `default` and `status`.                                                            |
| `languages`       | Locale records used by URLs and translations.                                                                                                                      |
| `blueprints`      | Catalogue of page, widget and section kinds. Key columns: `type`, `key`, `group`, `component`, `view_file`, `meta`, `default`, `status`. Unique on `type` + `key`. |
| `layouts`         | Page render layout. Columns: `key`, `site_id`, `theme_id`, `group`, `meta`, `containers`, `elements`, `default`, `status`. LayoutBuilder uses `containers`.        |
| `pages`           | Nested-set tree of pages. Points to `site_id`, `blueprint_id`, `layout_id`; has `uuid`, `meta` and visibility dates (`visible_from`, `visible_until`).             |
| `page_urls`       | Public URL records. Uses `pageable_type`, `pageable_id`, `language_id`, `site_id`, `url`, `type` (`alias` or `redirect`), `status_code` and `is_manual`.           |
| `translations`    | Translatable title, content and meta for any translated model. Unique on `language_id`, `translatable_type` and `translatable_id`.                                 |
| `asset_relations` | Generic ordered asset links for models that use Capell assets.                                                                                                     |

Optional packages add their own tables, so inspect the schema before assuming one exists.

## Idempotent Page Seeder Shape

Pages have no unique name, so do not use `name` as the natural key. Key the seeder on the page's public URL for the site and language, then update the mutable fields.

```php
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;

$site = Site::query()->where('default', true)->firstOrFail();
$language = Language::query()->where('default', true)->firstOrFail();
$blueprint = Blueprint::query()->where('type', 'page')->where('key', 'default')->firstOrFail();
$layout = Layout::query()->where('site_id', $site->id)->where('key', 'default')->firstOrFail();

$existingUrl = PageUrl::query()
    ->where('site_id', $site->id)
    ->where('language_id', $language->id)
    ->where('url', 'about')
    ->first();

$page = $existingUrl?->pageable ?? new Page();
$page->fill([
    'name' => 'About',
    'site_id' => $site->id,
    'blueprint_id' => $blueprint->id,
    'layout_id' => $layout->id,
])->save();

PageUrl::query()->updateOrCreate(
    [
        'site_id' => $site->id,
        'language_id' => $language->id,
        'pageable_type' => $page->getMorphClass(),
        'pageable_id' => $page->id,
    ],
    ['url' => 'about', 'type' => 'alias', 'status' => true],
);

Translation::query()->updateOrCreate(
    [
        'language_id' => $language->id,
        'translatable_type' => $page->getMorphClass(),
        'translatable_id' => $page->id,
    ],
    ['title' => 'About', 'content' => null, 'meta' => ['seo_title' => 'About']],
);
```

Pages are nested sets: create child pages through the model's tree methods rather than writing `parent_id` and the left and right columns by hand. Redirects from an old route belong in `page_urls` with `type` set to `redirect`.

## Page Blueprints

Create a custom page blueprint when a page needs custom schema, routing behavior, render data, or permissions. Keep generic marketing/information pages on the default page blueprint.

Use `php artisan capell:make:blueprint <Name> --no-interaction` when available (`capell:make-blueprint` and `capell:make-type` are aliases), then register through Capell extension points in `AppServiceProvider` or the package provider.

## Translation Rule

Everything visible to visitors or editors must be translatable. Seed text into `translations`, LayoutBuilder `sections` translations, widget/content block translations, or lang files. Blade templates should render data, not contain final marketing copy after conversion.

## Static Blade Conversion Checklist

1. Inventory routes, views, components, forms, assets, and dynamic logic.
2. Keep non-CMS flows as Laravel routes/controllers/Livewire components.
3. Create CMS pages and URLs for editorial pages.
4. Move repeated content into LayoutBuilder sections, widget assets, or Block Library content blocks.
5. Move copy into translations or lang files.
6. Add tests that seed CMS records and assert URLs render expected translated content.
