<?php

declare(strict_types=1);

use Capell\Core\Enums\ContentStructure;
use Capell\Core\Models\Blueprint;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Frontend\Support\State\FrontendState;

require_once __DIR__ . '/DomQuery.php';

/** @return array{page: Page, media: Media} */
function frontendViewFixture(bool $system = false): array
{
    $site = new Site(['name' => 'Public site', 'meta' => ['logo_blade_view' => false]]);
    $page = new Page;
    $page->setRelation('translation', new Translation(['title' => 'Public title', 'content' => '<p>Public body</p>']));
    $page->setRelation('blueprint', new Blueprint(['meta' => ['content_structure' => ContentStructure::Html->value]]));
    $page->setRelation('pageUrl', new PageUrl(['url' => '/public-page'])->setRelation('siteDomain', new SiteDomain(['domain' => 'example.test', 'scheme' => 'https', 'path' => ''])));

    $layout = new Layout(['admin' => ['system_page_layout' => $system]]);
    $theme = new Theme(['meta' => ['header' => false, 'footer' => false]]);
    $media = new Media([
        'id' => 1, 'name' => 'Public image', 'file_name' => 'public.jpg',
        'disk' => 'public', 'mime_type' => 'image/jpeg',
        'custom_properties' => ['width' => 640, 'height' => 360],
        'generated_conversions' => [], 'responsive_images' => [],
    ]);
    $media->setRelation('translations', collect());

    $page->setRelation('media', collect([$media]));
    $site->setRelation('translation', new Translation(['title' => 'Public site']));

    resolve(FrontendState::class)->withSite($site)->withPage($page)->withLayout($layout)
        ->withTheme($theme)->withLanguage(new Language(['code' => 'en']));

    return ['page' => $page, 'media' => $media];
}

function frontendRenderedDom(string $html): DOMXPath
{
    return domXPath($html);
}

function frontendHasClass(string $html, string $class): bool
{
    return domCount(frontendRenderedDom($html), '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]') > 0;
}
