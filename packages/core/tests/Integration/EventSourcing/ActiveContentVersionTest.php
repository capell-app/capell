<?php

declare(strict_types=1);

use Capell\Core\EventSourcing\Rollback\Actions\ApplyRollbackAction;
use Capell\Core\EventSourcing\Rollback\RollbackService;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;

function recordRevisionForActiveContent(Page $page): void
{
    // Re-fire the recording bridge the way a real save would: reload relations,
    // then save so PageSaved is dispatched and a revision is captured.
    $page->load(['translations', 'pageUrls']);
    $page->save();
}

it('reports the head version as active content for a normally-edited page', function (): void {
    $page = Page::factory()->create();
    $language = Language::factory()->create();

    $translation = Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    recordRevisionForActiveContent($page);

    $translation->forceFill(['title' => 'Second', 'content' => '<p>second</p>'])->save();
    recordRevisionForActiveContent($page);

    $service = resolve(RollbackService::class);

    expect($service->activeContentVersion($page->uuid))
        ->toBe($service->currentVersion($page->uuid));
});

it('reports the rollback origin (not the head) as active content after a rollback', function (): void {
    $page = Page::factory()->create();
    $language = Language::factory()->create();

    $translation = Translation::factory()->translatable($page)->language($language)
        ->create(['title' => 'First', 'content' => '<p>first</p>']);
    recordRevisionForActiveContent($page);

    $firstVersion = resolve(RollbackService::class)->currentVersion($page->uuid);

    $translation->forceFill(['title' => 'Second', 'content' => '<p>second</p>'])->save();
    recordRevisionForActiveContent($page);

    // Roll back to the first version. The head event is now a rollback whose
    // origin is the first version, so the active content version must follow the
    // origin — not the (newer) head — which is what makes "roll forward" possible.
    ApplyRollbackAction::run($page->fresh(), $firstVersion);

    $service = resolve(RollbackService::class);

    expect($service->activeContentVersion($page->uuid))->toBe($firstVersion)
        ->and($service->activeContentVersion($page->uuid))
        ->toBeLessThan($service->currentVersion($page->uuid));
});

it('returns zero for an aggregate with no events', function (): void {
    expect(resolve(RollbackService::class)->activeContentVersion('00000000-0000-0000-0000-000000000000'))
        ->toBe(0);
});
