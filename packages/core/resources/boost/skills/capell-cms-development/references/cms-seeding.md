# Capell CMS Seeding Reference

## Core Tables

| Table             | Purpose                                                                                                                         |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| `sites`           | Publishing surface with default language/theme/type.                                                                            |
| `languages`       | Locale records used by URLs and translations.                                                                                   |
| `types`           | Polymorphic type catalogue. Key columns: `type`, `key`, `group`, `meta`, `admin`.                                               |
| `layouts`         | Page render layout. Core columns: `key`, `site_id`, `theme_id`, `meta`, `admin`; LayoutBuilder adds `containers` and `widgets`. |
| `pages`           | Tree of page records. Points to `site_id`, `type_id`, `layout_id`.                                                              |
| `page_urls`       | Public URL records for pages. Uses `pageable_type`, `pageable_id`, `language_id`, `site_id`, `url`.                             |
| `translations`    | Translatable title/content/meta for any translated model. Uses `language_id`, `translatable_type`, `translatable_id`.           |
| `asset_relations` | Generic ordered asset links for models that use Capell assets.                                                                  |

## Idempotent Page Seeder Shape

Use natural keys and update mutable fields. Do not rely on auto IDs.

```php
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Models\Type;

$site = Site::query()->where('default', true)->firstOrFail();
$language = Language::query()->where('default', true)->firstOrFail();
$type = Type::query()->where('type', 'page')->where('key', 'default')->firstOrFail();
$layout = Layout::query()->where('site_id', $site->id)->where('key', 'default')->firstOrFail();

$page = Page::query()->updateOrCreate(
    ['site_id' => $site->id, 'name' => 'About'],
    ['type_id' => $type->id, 'layout_id' => $layout->id, 'status' => true],
);

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

## Page Types

Create a custom page type when a page needs custom schema, routing behavior, render data, or permissions. Keep generic marketing/information pages on the default page type.

Use `php artisan capell:make-type <Name> --no-interaction` when available, then register through Capell extension points in `AppServiceProvider` or the package provider.

## Translation Rule

Everything visible to visitors or editors must be translatable. Seed text into `translations`, LayoutBuilder `sections` translations, widget/content block translations, or lang files. Blade templates should render data, not contain final marketing copy after conversion.

## Static Blade Conversion Checklist

1. Inventory routes, views, components, forms, assets, and dynamic logic.
2. Keep non-CMS flows as Laravel routes/controllers/Livewire components.
3. Create CMS pages and URLs for editorial pages.
4. Move repeated content into LayoutBuilder sections, widget assets, or Block Library content blocks.
5. Move copy into translations or lang files.
6. Add tests that seed CMS records and assert URLs render expected translated content.
