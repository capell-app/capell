<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Workbench\App\Http\Middleware\RequireScreenshotAdmin;
use Workbench\App\Support\MarketplaceFixture;
use Workbench\App\Support\PageBuildingBlocksFixture;
use Workbench\App\Support\PageHistoryFixture;
use Workbench\App\Support\RecordStateScreenshotFixture;

Route::get('/screenshot-fixtures/page-building-blocks-editor', static fn (): RedirectResponse => redirect()->to(PageBuildingBlocksFixture::editUrl()))
    ->middleware('web');

Route::get('/admin/screenshot-fixtures/page-building-blocks-editor', static fn (): RedirectResponse => redirect()->to(PageBuildingBlocksFixture::editUrl()))
    ->middleware('web');

Route::get('/screenshot-fixtures/page-history', static fn (): RedirectResponse => redirect()->to(PageHistoryFixture::editUrl()))
    ->middleware('web');

// These list aliases exercise authenticated fixture redirects in regression tests.
// Capture manifests use the canonical Filament list URLs after pre-capture seeding.
Route::get('/screenshot-fixtures/record-states/pages', static fn (): RedirectResponse => redirect()->to(RecordStateScreenshotFixture::pagesUrl()))
    ->middleware(['web', RequireScreenshotAdmin::class]);

Route::get('/screenshot-fixtures/record-states/layouts', static fn (): RedirectResponse => redirect()->to(RecordStateScreenshotFixture::layoutsUrl()))
    ->middleware(['web', RequireScreenshotAdmin::class]);

Route::get('/screenshot-fixtures/record-states/page-editor', static fn (): RedirectResponse => redirect()->to(RecordStateScreenshotFixture::pageEditUrl()))
    ->middleware(['web', RequireScreenshotAdmin::class]);

Route::get('/screenshot-fixtures/record-states/media', static fn (): RedirectResponse => redirect()->to(RecordStateScreenshotFixture::mediaListUrl()))
    ->middleware(['web', RequireScreenshotAdmin::class]);

Route::get('/screenshot-fixtures/record-states/media-editor', static fn (): RedirectResponse => redirect()->to(RecordStateScreenshotFixture::mediaEditUrl()))
    ->middleware(['web', RequireScreenshotAdmin::class]);

Route::get('/api/v1/marketplace-fixtures/seo-suite/{image}.png', static fn (string $image): BinaryFileResponse => response()->file(MarketplaceFixture::imagePath($image . '.png')))
    ->where('image', '[0-9]+');
