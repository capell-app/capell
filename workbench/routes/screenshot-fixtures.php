<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Workbench\App\Http\Middleware\RequireScreenshotAdmin;
use Workbench\App\Support\MarketplaceFixture;
use Workbench\App\Support\PageBuildingBlocksFixture;
use Workbench\App\Support\PageHistoryFixture;
use Workbench\App\Support\RecordStateScreenshotFixture;

// The shared runner verifies the same authenticated web session immediately
// before recording every admin screenshot. Keep this workbench-only endpoint
// read-only and fail closed for guests.
Route::get('/_capell/screenshots/auth-probe', static function (Request $request): JsonResponse {
    $actor = $request->user();

    abort_unless($actor instanceof Authenticatable && $actor instanceof Model, 401);

    $email = $actor->getAttribute('email');
    abort_unless(is_string($email) && $email !== '', 401);

    return response()->json([
        'authenticated' => true,
        'email' => $email,
    ])->header('Cache-Control', 'no-store, private');
})->middleware('web');

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
