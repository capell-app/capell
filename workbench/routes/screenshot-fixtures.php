<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Workbench\App\Support\MarketplaceFixture;
use Workbench\App\Support\PageBuildingBlocksFixture;

Route::get('/screenshot-fixtures/login', static function (): RedirectResponse {
    $userModel = (string) config('auth.providers.users.model');
    $user = $userModel::query()
        ->where('email', env('CAPELL_SCREENSHOT_ADMIN_EMAIL', 'admin@example.com'))
        ->firstOrFail();

    assert($user instanceof Authenticatable);

    auth()->login($user);
    request()->session()->regenerate();

    return redirect('/admin');
})->middleware('web');

Route::get('/screenshot-fixtures/page-building-blocks-editor', static fn (): RedirectResponse => redirect()->to(PageBuildingBlocksFixture::editUrl()));

Route::get('/admin/screenshot-fixtures/page-building-blocks-editor', static fn (): RedirectResponse => redirect()->to(PageBuildingBlocksFixture::editUrl()));

Route::get('/api/v1/marketplace-fixtures/seo-suite/{image}.svg', static fn (string $image): Response => response(MarketplaceFixture::imageSvg($image), 200)
    ->header('Content-Type', 'image/svg+xml'))->where('image', '[A-Za-z0-9_-]+');
