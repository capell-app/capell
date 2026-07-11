<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Media\Pages\ListMedia;
use Capell\Admin\Filament\Resources\Media\Tables\MediaTable;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

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
