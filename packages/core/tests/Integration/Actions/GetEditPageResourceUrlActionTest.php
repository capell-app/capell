<?php

declare(strict_types=1);

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

it('gracefully handles missing resource', function (): void {
    $page = Page::factory()->createOne();

    $url = GetEditPageResourceUrlAction::run($page);

    expect($url)->toBeNull();
});

it('resolves page ids through the morph map before using the admin route fallback', function (): void {
    Route::get('/admin/pages/{record}/edit', fn (int $record): string => 'edit ' . $record)
        ->name('filament.admin.resources.pages.edit');
    resolve(Router::class)->getRoutes()->refreshNameLookups();
    Relation::morphMap([
        ...Relation::morphMap(),
        'page' => Page::class,
    ], false);

    $page = Page::factory()->createOne();

    expect(Route::has('filament.admin.resources.pages.edit'))->toBeTrue();

    expect((string) GetEditPageResourceUrlAction::run($page->getKey(), 'page'))
        ->toMatch('#/admin/pages/' . $page->getKey() . '/edit#');
});

it('fails clearly when resolving a page id without a valid morph type', function (): void {
    expect(fn (): ?string => GetEditPageResourceUrlAction::run(123))
        ->toThrow(InvalidArgumentException::class, 'Page type is required')
        ->and(fn (): ?string => GetEditPageResourceUrlAction::run(123, 'missing'))
        ->toThrow(InvalidArgumentException::class, 'Invalid page type');
});
