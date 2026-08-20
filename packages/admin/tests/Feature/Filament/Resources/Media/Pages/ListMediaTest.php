<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\BuildMediaUsageItemsAction;
use Capell\Admin\Actions\Media\CreateExternalVideoMediaAction;
use Capell\Admin\Filament\Resources\Media\MediaResource;
use Capell\Admin\Filament\Resources\Media\Pages\ListMedia;
use Capell\Admin\Filament\Resources\Media\Tables\MediaTable;
use Capell\Admin\Tests\Support\ScopedAdminUser;
use Capell\Core\Data\Media\ExternalVideoData;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(CreatesAdminUser::class)
    ->group('media');

beforeEach(function (): void {
    test()->actingAsAdmin();

    Storage::fake('local');
    Storage::fake('public');
    config()->set('capell.media.model', CapellMedia::class);
    config()->set('media-library.media_model', CapellMedia::class);
});

it('lists media with owner labels filters and owner edit actions', function (): void {
    Route::get('/admin/pages/{record}/edit', fn (): string => '')
        ->name('filament.admin.resources.pages.edit');

    $owner = Page::factory()->createOne(['name' => 'Media owner page']);
    $otherOwner = Page::factory()->createOne(['name' => 'Document owner page']);

    $image = $owner
        ->addMedia(UploadedFile::fake()->image('hero.jpg', 600, 400))
        ->usingName('Hero image')
        ->toMediaCollection('images');

    $document = $otherOwner
        ->addMedia(UploadedFile::fake()->create('terms.pdf', 20, 'application/pdf'))
        ->usingName('Terms PDF')
        ->toMediaCollection('documents');
    $document->forceFill(['mime_type' => 'application/pdf'])->save();

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->toggleAllTableColumns()
        ->assertCanSeeTableRecords([$image, $document])
        ->assertSee(__('capell-admin::media.mime_groups.image'))
        ->assertSee(__('capell-admin::media.mime_groups.pdf'))
        ->assertSee('hero.jpg')
        ->assertSee('terms.pdf')
        ->assertSee('Media owner page')
        ->assertTableActionVisible('open-owner', $image);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('collection_name', 'images')
        ->assertCanSeeTableRecords([$image])
        ->assertCanNotSeeTableRecords([$document]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('mime_group', 'application/pdf')
        ->assertCanSeeTableRecords([$document])
        ->assertCanNotSeeTableRecords([$image]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('mime_group', 'image')
        ->assertCanSeeTableRecords([$image])
        ->assertCanNotSeeTableRecords([$document]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('model_type', $image->model_type)
        ->assertCanSeeTableRecords([$image, $document]);
});

it('hides owner edit actions when the actor cannot edit the owner record', function (): void {
    Route::get('/admin/pages/{record}/edit', fn (): string => '')
        ->name('filament.admin.resources.pages.edit');

    $owner = Page::factory()->createOne(['name' => 'Media owner page']);
    $image = $owner
        ->addMedia(UploadedFile::fake()->image('hero.jpg', 600, 400))
        ->usingName('Hero image')
        ->toMediaCollection('images');

    expect(MediaTable::getOwnerUrl($image))->not->toBeNull();

    test()->actingAsUser();

    $image->refresh();

    expect(MediaTable::getOwnerUrl($image))->toBeNull();
});

it('hides owner actions when the related model cannot be resolved to an admin resource', function (): void {
    $orphan = CapellMedia::query()->create([
        'model_type' => Page::class,
        'model_id' => 123,
        'uuid' => (string) Str::uuid(),
        'collection_name' => 'images',
        'name' => 'Orphan',
        'file_name' => 'orphan.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'conversions_disk' => 'public',
        'size' => 100,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
    ]);

    expect(MediaTable::getOwnerUrl($orphan))->toBeNull();

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$orphan])
        ->assertTableActionHidden('open-owner', $orphan);
});

it('edits modal-only media owners from the grouped owner actions', function (): void {
    $theme = Theme::factory()->createOne(['name' => 'Media owner theme']);
    $media = $theme
        ->addMedia(UploadedFile::fake()->image('theme.jpg', 600, 400))
        ->toMediaCollection('images');

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$media])
        ->assertTableActionVisible('edit-owner-theme', $media)
        ->mountTableAction('edit-owner-theme', $media)
        ->assertMountedActionModalSee('Media owner theme');
});

it('creates YouTube video media from the list page action', function (): void {
    $site = Site::factory()->createOne(['name' => 'Capell']);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->callAction('add-youtube-video', [
            'name' => 'Product tour',
            'youtube_url' => 'https://youtu.be/FgalLC99jzY',
            'site_id' => $site->getKey(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $media = CapellMedia::query()
        ->where('model_type', $site->getMorphClass())
        ->where('model_id', $site->getKey())
        ->where('collection_name', MediaCollectionEnum::Video->value)
        ->firstOrFail();

    expect($media->name)->toBe('Product tour')
        ->and($media->externalVideo()?->provider)->toBe('youtube')
        ->and($media->externalVideo()?->videoId)->toBe('FgalLC99jzY');
});

it('uses the external video thumbnail in the media table instead of a storage URL', function (): void {
    $site = Site::factory()->createOne(['name' => 'Capell']);
    $video = expectPresent(YouTubeVideoUrl::parse('https://youtu.be/FgalLC99jzY'));

    $media = CreateExternalVideoMediaAction::run($site, 'Product tour', $video);

    expect($media->original_url)->toBe($video->thumbnailUrl)
        ->and($media->original_url)->not->toContain('/storage/')
        ->and($media->original_url)->not->toEndWith('.youtube');

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertSee($video->thumbnailUrl)
        ->assertDontSee('/storage/' . $media->getKey() . '/' . $media->file_name);
});

it('does not render a synthetic storage preview for a local external video', function (): void {
    $site = Site::factory()->createOne(['name' => 'Capell']);
    $video = new ExternalVideoData(
        provider: 'local',
        videoId: 'capell-launch-film',
        url: '/_capell/marketing/page-videos/capell-launch-film/capell-launch-film.mp4',
        embedUrl: '/_capell/marketing/page-videos/capell-launch-film/capell-launch-film.mp4',
        thumbnailUrl: '/_capell/marketing/page-videos/capell-launch-film/capell-launch-film-poster.jpg',
    );

    $media = CreateExternalVideoMediaAction::run($site, 'Capell launch film', $video);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$media])
        ->assertDontSee('/storage/' . $media->getKey() . '/' . $media->file_name)
        ->assertDontSee($video->thumbnailUrl);
});

it('bulk uploads files to a site uploads collection', function (): void {
    $site = Site::factory()->createOne(['name' => 'Capell']);

    Storage::disk('local')->put('media-uploads/hero-a.jpg', UploadedFile::fake()->image('hero-a.jpg')->getContent());
    Storage::disk('local')->put('media-uploads/hero-b.jpg', UploadedFile::fake()->image('hero-b.jpg')->getContent());

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->callAction('upload-files', [
            'site_id' => $site->getKey(),
            'files' => [
                'media-uploads/hero-a.jpg',
                'media-uploads/hero-b.jpg',
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $uploads = CapellMedia::query()
        ->where('model_type', $site->getMorphClass())
        ->where('model_id', $site->getKey())
        ->where('collection_name', 'uploads')
        ->orderBy('file_name')
        ->get();

    expect($uploads)->toHaveCount(2)
        ->and($uploads->pluck('name')->all())->toBe(['hero-a', 'hero-b']);
});

it('shows usage counts and can filter recently deleted media', function (): void {
    $owner = Page::factory()->createOne(['name' => 'Media owner page']);
    $image = CapellMedia::factory()
        ->model($owner)
        ->createOne(['name' => 'Hero image', 'file_name' => 'hero.jpg']);
    $deletedImage = CapellMedia::factory()
        ->model($owner)
        ->createOne(['name' => 'Deleted hero', 'file_name' => 'deleted-hero.jpg']);

    AssetAttachment::query()->create([
        'related_type' => $owner->getMorphClass(),
        'related_id' => $owner->getKey(),
        'asset_type' => $image->getMorphClass(),
        'asset_id' => $image->getKey(),
        'order' => 1,
    ]);

    $deletedImage->delete();

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$image])
        ->assertCanNotSeeTableRecords([$deletedImage])
        ->assertTableColumnExists('usage_count')
        ->assertTableColumnStateSet('usage_count', 1, $image);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('trashed', false)
        ->assertCanSeeTableRecords([$deletedImage])
        ->assertCanNotSeeTableRecords([$image]);
});

it('projects tracked usage counts without per-row attachment queries', function (): void {
    $owner = Page::factory()->createOne();
    $media = CapellMedia::factory()
        ->count(6)
        ->model($owner)
        ->create();

    $media->each(function (CapellMedia $item, int $index) use ($owner): void {
        AssetAttachment::query()->create([
            'related_type' => $owner->getMorphClass(),
            'related_id' => $owner->getKey(),
            'asset_type' => $item->getMorphClass(),
            'asset_id' => $item->getKey(),
            'order' => $index + 1,
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords($media)
        ->assertTableColumnStateSet('usage_count', 1, $media->first());

    $perRowAttachmentCounts = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(static fn (string $query): bool => str_starts_with(strtolower($query), 'select count(*)')
            && str_contains($query, 'asset_attachments'));

    expect($perRowAttachmentCounts)->toBeEmpty();
});

it('filters media by tracked attachment use and links positive usage counts', function (): void {
    $owner = Page::factory()->createOne();

    Permission::findOrCreate('Update:Media');
    test()->authenticatedUser()->givePermissionTo('Update:Media');

    $usedMedia = CapellMedia::factory()->model($owner)->createOne([
        'name' => 'Used image',
        'file_name' => 'used.jpg',
    ]);
    $unusedMedia = CapellMedia::factory()->model($owner)->createOne([
        'name' => 'No tracked uses image',
        'file_name' => 'no-tracked-uses.jpg',
    ]);

    AssetAttachment::query()->create([
        'related_type' => $owner->getMorphClass(),
        'related_id' => $owner->getKey(),
        'asset_type' => $usedMedia->getMorphClass(),
        'asset_id' => $usedMedia->getKey(),
        'order' => 1,
    ]);

    $livewire = Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertTableFilterExists('tracked_use')
        ->filterTable('tracked_use', 'unused')
        ->assertCanSeeTableRecords([$unusedMedia])
        ->assertCanNotSeeTableRecords([$usedMedia])
        ->assertSee(__('capell-admin::table.no_tracked_uses'));

    $usedLivewire = Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('tracked_use', 'used')
        ->assertCanSeeTableRecords([$usedMedia])
        ->assertCanNotSeeTableRecords([$unusedMedia]);

    $usageColumn = $livewire->instance()->getTable()->getColumn('usage_count');

    expect($usageColumn)->not->toBeNull();

    $usedMedia = $usedLivewire->instance()->getTableRecords()->firstWhere('id', $usedMedia->getKey());

    expect($usedMedia)->toBeInstanceOf(CapellMedia::class);

    $usageColumn->record($usedMedia);

    expect($usageColumn->getUrl($usageColumn->getState()))
        ->toBe(MediaResource::getUrl('edit', ['record' => $usedMedia]));

    $unusedMedia = $livewire->instance()->getTableRecords()->firstWhere('id', $unusedMedia->getKey());

    expect($unusedMedia)->toBeInstanceOf(CapellMedia::class);

    $usageColumn->record($unusedMedia);

    expect($usageColumn->getUrl($usageColumn->getState()))
        ->toBeNull()
        ->and($usageColumn->getTooltip($usageColumn->getState()))
        ->toBe(__('capell-admin::table.asset_usage_no_tracked_uses_tooltip'))
        ->toBe('No tracked uses');
});

it('unifies media health state filters and guarded bulk actions in the media workbench', function (): void {
    $owner = Page::factory()->createOne();
    $language = Language::factory()->english()->createOne();
    $missingAlt = CapellMedia::factory()->model($owner)->createOne([
        'name' => 'Needs alt text',
    ]);
    $healthy = CapellMedia::factory()->model($owner)->createOne([
        'name' => 'Healthy image',
    ]);

    Translation::query()->create([
        'language_id' => $language->getKey(),
        'translatable_type' => $missingAlt->getMorphClass(),
        'translatable_id' => $missingAlt->getKey(),
        'title' => 'Needs alt text',
        'meta' => ['alt' => '', 'credit' => 'Capell Studio'],
    ]);
    Translation::query()->create([
        'language_id' => $language->getKey(),
        'translatable_type' => $healthy->getMorphClass(),
        'translatable_id' => $healthy->getKey(),
        'title' => 'Healthy image',
        'meta' => ['alt' => 'A healthy image', 'credit' => 'Capell Studio'],
    ]);
    AssetAttachment::query()->create([
        'related_type' => $owner->getMorphClass(),
        'related_id' => $owner->getKey(),
        'asset_type' => $healthy->getMorphClass(),
        'asset_id' => $healthy->getKey(),
        'order' => 1,
    ]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertTableColumnExists('health_state')
        ->assertTableFilterExists('health')
        ->assertTableBulkActionExists('mark-media-decorative')
        ->assertTableBulkActionExists('delete-unused-media')
        ->assertTableColumnStateSet('health_state', 'missing_alt', $missingAlt)
        ->filterTable('health', 'missing_alt')
        ->assertCanSeeTableRecords([$missingAlt])
        ->assertCanNotSeeTableRecords([$healthy]);
});

it('limits tracked media usage to accessible related records', function (): void {
    $assignedSite = Site::factory()->createOne();
    $hiddenSite = Site::factory()->createOne();
    $layout = Layout::factory()->createOne(['site_id' => null]);
    $media = CapellMedia::factory()->model($layout)->createOne([
        'name' => 'Global layout image',
        'file_name' => 'global-layout.jpg',
    ]);
    $hiddenPage = Page::factory()->site($hiddenSite)->createOne([
        'name' => 'Hidden dependent page',
    ]);
    $visiblePage = Page::factory()->site($assignedSite)->createOne([
        'name' => 'Visible dependent page',
    ]);

    AssetAttachment::query()->create([
        'related_type' => $hiddenPage->getMorphClass(),
        'related_id' => $hiddenPage->getKey(),
        'asset_type' => $media->getMorphClass(),
        'asset_id' => $media->getKey(),
        'order' => 1,
    ]);
    AssetAttachment::query()->create([
        'related_type' => $visiblePage->getMorphClass(),
        'related_id' => $visiblePage->getKey(),
        'asset_type' => $media->getMorphClass(),
        'asset_id' => $media->getKey(),
        'order' => 1,
    ]);

    test()->actingAs(ScopedAdminUser::make(collect([$assignedSite->getKey()])));

    $livewire = Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$media])
        ->assertTableColumnStateSet('usage_count', 1, $media)
        ->filterTable('tracked_use', 'used')
        ->assertCanSeeTableRecords([$media]);

    Livewire::test(ListMedia::class)
        ->assertSuccessful()
        ->filterTable('tracked_use', 'unused')
        ->assertCanNotSeeTableRecords([$media]);

    $usageColumn = $livewire->instance()->getTable()->getColumn('usage_count');

    expect($usageColumn)->not->toBeNull();

    $media = $livewire->instance()->getTableRecords()->firstWhere('id', $media->getKey());

    expect($media)->toBeInstanceOf(CapellMedia::class);

    $usageColumn->record($media);

    expect($usageColumn->getUrl($usageColumn->getState()))
        ->toBe(MediaResource::getUrl('edit', ['record' => $media]))
        ->and($usageColumn->getTooltip($usageColumn->getState()))
        ->toBe(trans_choice('capell-admin::table.asset_usage_count_tooltip', 1, ['count' => 1]));

    $usageItems = BuildMediaUsageItemsAction::run($media);

    expect($usageItems)
        ->toHaveCount(2)
        ->and(collect($usageItems)->pluck('title')->all())
        ->toContain($visiblePage->name)
        ->not->toContain($hiddenPage->name);
});
