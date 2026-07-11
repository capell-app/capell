<?php

declare(strict_types=1);

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Support\Utils;
use Capell\Admin\Actions\Pages\CancelScheduledPageUnpublishAction;
use Capell\Admin\Actions\Pages\UnpublishPageAction;
use Capell\Core\Events\PageSaved;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class);

function pagePermission(string $affix): string
{
    $permissions = Utils::getConfig()->permissions;

    return FilamentShield::defaultPermissionKeyBuilder(
        affix: $affix,
        separator: $permissions->separator,
        subject: 'Page',
        case: $permissions->case,
    );
}

it('sets visible until to now for an authorized live page and emits page saved', function (): void {
    Event::fake([PageSaved::class]);

    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create([
        'visible_from' => now()->subDay(),
        'visible_until' => null,
    ]);

    $result = UnpublishPageAction::run($page, $actor);

    expect($result->changed)->toBeTrue()
        ->and($result->skipped)->toBeFalse()
        ->and($page->fresh()->visible_until)->not->toBeNull()
        ->and($page->fresh()->visible_until?->isPast())->toBeTrue();

    Event::assertDispatched(
        PageSaved::class,
        fn (PageSaved $event): bool => $event->page->getKey() === $page->getKey()
            && array_key_exists('visible_until', $event->formData),
    );
});

it('skips pages the actor cannot update', function (): void {
    Permission::findOrCreate(pagePermission('update'));

    $actor = test()->createUser();
    $page = Page::factory()->create([
        'visible_from' => now()->subDay(),
        'visible_until' => null,
    ]);

    $result = UnpublishPageAction::run($page, $actor);

    expect($result->changed)->toBeFalse()
        ->and($result->skipped)->toBeTrue()
        ->and($result->reason)->toBe('unauthorized')
        ->and($page->fresh()->visible_until)->toBeNull();
});

it('cancels a scheduled page unpublish and emits page saved', function (): void {
    Event::fake([PageSaved::class]);

    $actor = test()->actingAsAdmin()->authenticatedUser();
    $page = Page::factory()->create([
        'visible_from' => now()->subDay(),
        'visible_until' => now()->addWeek(),
    ]);

    $result = CancelScheduledPageUnpublishAction::run($page, $actor);

    expect($result->changed)->toBeTrue()
        ->and($result->skipped)->toBeFalse()
        ->and($page->fresh()->visible_until)->toBeNull();

    Event::assertDispatched(
        PageSaved::class,
        fn (PageSaved $event): bool => $event->page->getKey() === $page->getKey()
            && array_key_exists('visible_until', $event->formData),
    );
});
