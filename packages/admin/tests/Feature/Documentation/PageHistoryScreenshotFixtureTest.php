<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageRevision;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Workbench\App\Support\PageHistoryFixture;

uses(CreatesAdminUser::class)
    ->group('admin');

it('rebuilds deterministic page history with rollback and roll-forward targets', function (): void {
    test()->actingAsAdmin();
    Page::factory()->createOne();

    $url = PageHistoryFixture::editUrl();
    $page = Page::query()->where('name', 'Page history screenshot fixture')->firstOrFail();
    $revisions = PageRevision::query()
        ->where('page_uuid', $page->uuid)
        ->orderBy('version')
        ->get();

    expect($url)
        ->toContain(sprintf('/pages/%s/edit', $page->getRouteKey()))
        ->and($revisions)->toHaveCount(4)
        ->and($revisions->pluck('version')->all())->toBe([1, 2, 3, 4])
        ->and($revisions->pluck('is_rollback')->all())->toBe([false, false, false, true])
        ->and(resolve(RollbackService::class)->currentVersion($page->uuid))->toBe(4)
        ->and(resolve(RollbackService::class)->activeContentVersion($page->uuid))->toBe(2)
        ->and($page->translations()->firstOrFail()->title)->toBe('Homepage launch copy reviewed');
});
