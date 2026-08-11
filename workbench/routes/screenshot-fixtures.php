<?php

declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Middleware\RequireScreenshotAdmin;
use Workbench\App\Support\MarketplaceFixture;
use Workbench\App\Support\PageBuildingBlocksFixture;
use Workbench\App\Support\RecordStateScreenshotFixture;

Route::get('/screenshot-fixtures/page-building-blocks-editor', static fn (): RedirectResponse => redirect()->to(PageBuildingBlocksFixture::editUrl()))
    ->middleware('web');

Route::get('/admin/screenshot-fixtures/page-building-blocks-editor', static fn (): RedirectResponse => redirect()->to(PageBuildingBlocksFixture::editUrl()))
    ->middleware('web');

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

Route::get('/api/v1/marketplace-fixtures/seo-suite/{image}.svg', static fn (string $image): Response => response(MarketplaceFixture::imageSvg($image), 200)
    ->header('Content-Type', 'image/svg+xml'))->where('image', '[A-Za-z0-9_-]+');
