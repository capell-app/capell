<?php

declare(strict_types=1);

use Capell\Admin\Http\Middleware\SetSitePermissionScope;
use Capell\Admin\Tests\Fixtures\RetainedDraftSubclassPage;
use Capell\Core\Models\ContentLock;
use Capell\Core\Models\Page;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

use function Pest\Laravel\post;

it('applies site permission scope before content lock authorization', function (): void {
    foreach ([
        'capell-admin.api.pages.content-lock.heartbeat',
        'capell-admin.api.pages.content-lock.release',
    ] as $routeName) {
        $route = RouteFacade::getRoutes()->getByName($routeName);

        expect($route)->toBeInstanceOf(Route::class);
        assert($route instanceof Route);

        expect($route->gatherMiddleware())->toContain(SetSitePermissionScope::class);
    }
});

it('refreshes and releases the registered content type without touching a page with the same id', function (): void {
    test()->actingAsAdmin();
    $page = Page::factory()->createOne();
    $map = Relation::morphMap();
    Relation::morphMap(['companion_page' => RetainedDraftSubclassPage::class]);
    try {
        $record = RetainedDraftSubclassPage::query()->whereKey($page->getKey())->sole();
        $pageLock = ContentLock::query()->create([
            'user_id' => test()->authenticatedUser()->getKey(),
            'model_type' => $page->getMorphClass(),
            'model_id' => $page->getKey(),
            'expires_at' => now()->addMinute(),
        ]);
        $parameters = ['page' => $record, 'type' => 'companion_page'];
        post(route('capell-admin.api.pages.content-lock.heartbeat', $parameters))->assertSuccessful();
        expect(ContentLock::query()->where('model_type', 'companion_page')->where('model_id', $record->getKey())->exists())->toBeTrue();
        post(route('capell-admin.api.pages.content-lock.release', $parameters))->assertSuccessful()->assertJsonPath('released', true);
        expect(ContentLock::query()->where('model_type', 'companion_page')->exists())->toBeFalse()
            ->and($pageLock->fresh()?->expires_at->equalTo($pageLock->expires_at))->toBeTrue();
    } finally {
        Relation::morphMap($map, merge: false);
    }
});

it('rejects unknown and non-pageable content lock types', function (string $type): void {
    test()->actingAsAdmin();
    $page = Page::factory()->createOne();
    post(route('capell-admin.api.pages.content-lock.heartbeat', ['page' => $page, 'type' => $type]))->assertNotFound();
    expect(ContentLock::query()->count())->toBe(0);
})->with(['unknown', RetainedDraftSubclassPage::class, 'site']);

it('authorizes the resolved record before refreshing its lock', function (): void {
    test()->actingAsUser();
    $page = Page::factory()->createOne();
    post(route('capell-admin.api.pages.content-lock.heartbeat', ['page' => $page, 'type' => $page->getMorphClass()]))->assertForbidden();
    expect(ContentLock::query()->count())->toBe(0);
});
