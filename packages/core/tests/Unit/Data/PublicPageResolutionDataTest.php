<?php

declare(strict_types=1);

use Capell\Core\Contracts\Pageable;
use Capell\Core\Data\PublicPageFieldsData;
use Capell\Core\Data\PublicPageResolutionData;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Site;

it('keeps runtime models accessible without serializing them', function (): void {
    $page = Mockery::mock(Pageable::class);
    $site = new Site;
    $language = new Language;
    $layout = new Layout;
    $fields = new PublicPageFieldsData(url: '/about', title: 'About');
    $resolution = new PublicPageResolutionData($page, $site, $language, $layout, $fields);

    expect($resolution->page)->toBe($page)
        ->and($resolution->site)->toBe($site)
        ->and($resolution->language)->toBe($language)
        ->and($resolution->layout)->toBe($layout)
        ->and($resolution->toArray())->toBe([
            'fields' => [
                'url' => '/about',
                'title' => 'About',
                'content' => null,
                'meta' => [],
            ],
        ]);
});
