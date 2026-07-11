<?php

declare(strict_types=1);

use Capell\Admin\Filament\Pages\RecentlyDeletedPage;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('page', 'media');

beforeEach(function (): void {
    test()->actingAsAdmin();

    config()->set('capell.media.model', CapellMedia::class);
    config()->set('media-library.media_model', CapellMedia::class);
});

it('lists and restores recently deleted pages and media', function (): void {
    $page = Page::factory()->createOne(['name' => 'Archived page']);
    $media = CapellMedia::factory()
        ->model($page)
        ->createOne(['name' => 'Archived media', 'file_name' => 'archived-media.jpg']);

    $page->delete();
    $media->delete();

    Livewire::test(RecentlyDeletedPage::class)
        ->assertSuccessful()
        ->assertSee('Archived page')
        ->assertSee('Archived media')
        ->call('restoreRecord', 'page', $page->getKey())
        ->assertNotified(__('capell-admin::message.recently_deleted_restored'))
        ->call('restoreRecord', 'media', $media->getKey())
        ->assertNotified(__('capell-admin::message.recently_deleted_restored'));

    expect($page->fresh()->trashed())->toBeFalse()
        ->and($media->fresh()->trashed())->toBeFalse();
});

it('permanently deletes recently deleted records', function (): void {
    $page = Page::factory()->createOne(['name' => 'Disposable page']);

    $page->delete();

    Livewire::test(RecentlyDeletedPage::class)
        ->assertSuccessful()
        ->call('forceDeleteRecord', 'page', $page->getKey())
        ->assertNotified(__('capell-admin::message.recently_deleted_force_deleted'));

    expect(Page::query()->withTrashed()->find($page->getKey()))->toBeNull();
});
