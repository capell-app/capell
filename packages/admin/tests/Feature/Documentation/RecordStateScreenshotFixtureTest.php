<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Page\LayoutSelect;
use Capell\Admin\Filament\Resources\Layouts\LayoutResource;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Pages\PageResource;
use Capell\Core\Enums\PublishVisibilityStateEnum;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Workbench\App\Support\RecordStateScreenshotFixture;

uses(CreatesAdminUser::class)
    ->group('admin');

beforeEach(function (): void {
    require dirname(__DIR__, 5) . '/workbench/routes/screenshot-fixtures.php';

    Page::factory()->createOne();
});

it('rebuilds idempotent record-state fixtures at actual Filament list and edit URLs', function (): void {
    test()->actingAsAdmin();

    $pagesUrl = RecordStateScreenshotFixture::pagesUrl();
    $layoutsUrl = RecordStateScreenshotFixture::layoutsUrl();
    $pageEditUrl = RecordStateScreenshotFixture::pageEditUrl();
    $mediaListUrl = RecordStateScreenshotFixture::mediaListUrl();
    $mediaEditUrl = RecordStateScreenshotFixture::mediaEditUrl();

    $page = RecordStateScreenshotFixture::page();
    $layout = RecordStateScreenshotFixture::disabledLayout();
    $media = RecordStateScreenshotFixture::media();
    $pageUrl = PageUrl::query()->where('pageable_id', $page->getKey())->sole();

    expect($pagesUrl)->toBe(PageResource::getUrl('index'))
        ->and($layoutsUrl)->toBe(LayoutResource::getUrl('index'))
        ->and($pageEditUrl)->toBe(PageResource::getUrl('edit', ['record' => $page]))
        ->and($mediaListUrl)->toBe(MediaResource::getUrl('index'))
        ->and($mediaEditUrl)->toBe(MediaResource::getUrl('edit', ['record' => $media]))
        ->and($page->visible_from?->isFuture())->toBeTrue()
        ->and($page->publishVisibilityState())->toBe(PublishVisibilityStateEnum::scheduled)
        ->and($pageUrl->status)->toBeFalse()
        ->and($layout->status)->toBeFalse()
        ->and($layout->pages()->count())->toBe(0)
        ->and($media->usage_count)->toBe(0)
        ->and(AssetAttachment::query()->where('asset_id', (string) $media->getKey())->count())->toBe(0)
        ->and(LayoutSelect::make('layout_id')->isHtmlAllowed())->toBeTrue();

    expect(RecordStateScreenshotFixture::pagesUrl())->toBe($pagesUrl)
        ->and(RecordStateScreenshotFixture::page()->getKey())->toBe($page->getKey())
        ->and(RecordStateScreenshotFixture::disabledLayout()->getKey())->toBe($layout->getKey())
        ->and(RecordStateScreenshotFixture::media()->getKey())->toBe($media->getKey());
});

it('redirects screenshot fixture routes to their seeded Filament surfaces', function (): void {
    test()->actingAsAdmin();

    test()->get('/screenshot-fixtures/record-states/pages')
        ->assertRedirect(PageResource::getUrl('index'));

    test()->get('/screenshot-fixtures/record-states/layouts')
        ->assertRedirect(LayoutResource::getUrl('index'));

    test()->get('/screenshot-fixtures/record-states/page-editor')
        ->assertRedirect(RecordStateScreenshotFixture::pageEditUrl());

    test()->get('/screenshot-fixtures/record-states/media')
        ->assertRedirect(MediaResource::getUrl('index'));

    test()->get('/screenshot-fixtures/record-states/media-editor')
        ->assertRedirect(RecordStateScreenshotFixture::mediaEditUrl());
});
