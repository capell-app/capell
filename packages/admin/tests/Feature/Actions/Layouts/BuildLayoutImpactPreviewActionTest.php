<?php

declare(strict_types=1);

use Capell\Admin\Actions\Layouts\BuildLayoutImpactPreviewAction;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Data\EditorImpact\EditorImpactPreviewData;
use Capell\Core\Enums\UrlTypeEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Tests\Support\Concerns\CreatesAdminUser;

uses(CreatesAdminUser::class)
    ->group('layout');

it('reports accessible pages, locales, and public urls for a layout', function (): void {
    test()->actingAsAdmin();

    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()
        ->language($english)
        ->withTranslations([$english, $french], siteDomainData: [
            'domain' => 'example.test',
            'scheme' => 'http',
            'path' => null,
        ])
        ->createOne();
    $layout = Layout::factory()->site($site)->createOne();
    $page = Page::factory()->site($site)->layout($layout)->createOne(['name' => 'Shared landing']);

    PageUrl::factory()->page($page)->site($site)->language($english)->createOne(['url' => '/landing']);
    PageUrl::factory()->page($page)->site($site)->language($french)->createOne(['url' => '/landing']);
    PageUrl::factory()->page($page)->site($site)->language($english)->createOne([
        'url' => '/old-landing',
        'type' => UrlTypeEnum::Redirect,
    ]);

    $preview = BuildLayoutImpactPreviewAction::run($layout);

    expect($preview)->toBeInstanceOf(EditorImpactPreviewData::class)
        ->and($preview->pageCount)->toBe(1)
        ->and($preview->siteCount)->toBe(1)
        ->and($preview->localeCount)->toBe(2)
        ->and($preview->pages[0]->name)->toBe('Shared landing')
        ->and($preview->pages[0]->site)->toBe($site->name)
        ->and($preview->pages[0]->locales)->toBe(['en', 'fr'])
        ->and($preview->pages[0]->urls[0]->url)->toBe('http://example.test/landing')
        ->and($preview->pages[0]->urls[1]->url)->toBe('http://example.test/fr/landing');
});

it('limits the preview to assigned sites and fails closed without update access', function (): void {
    $assignedSite = Site::factory()->withTranslations()->createOne();
    $hiddenSite = Site::factory()->withTranslations()->createOne();
    $layout = Layout::factory()->createOne();
    $hiddenLayout = Layout::factory()->site($hiddenSite)->createOne();
    $assignedPage = Page::factory()->site($assignedSite)->layout($layout)->createOne();
    $hiddenPage = Page::factory()->site($hiddenSite)->layout($layout)->createOne();

    PageUrl::factory()->page($assignedPage)->site($assignedSite)->createOne();
    PageUrl::factory()->page($hiddenPage)->site($hiddenSite)->createOne();

    test()->actingAs(ScopedAdminUser::make(collect([$assignedSite->getKey()])));

    $preview = BuildLayoutImpactPreviewAction::run($layout);

    expect($preview)->not->toBeNull()
        ->and($preview->pageCount)->toBe(1)
        ->and($preview->pages[0]->name)->toBe($assignedPage->name);

    expect(BuildLayoutImpactPreviewAction::run($hiddenLayout))->toBeNull();

    auth()->logout();

    expect(BuildLayoutImpactPreviewAction::run($layout))->toBeNull();
});
