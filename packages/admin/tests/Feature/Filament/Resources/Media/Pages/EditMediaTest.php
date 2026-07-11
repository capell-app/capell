<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\BuildMediaUsageItemsAction;
use Capell\Admin\Contracts\Extenders\MediaEditActionExtender;
use Capell\Admin\Filament\Resources\Media\Pages\EditMedia;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

uses(CreatesAdminUser::class)
    ->group('media');

beforeEach(function (): void {
    test()->actingAs(createGlobalMediaAdminUser());

    Storage::fake('public');
    config()->set('capell.media.model', CapellMedia::class);
    config()->set('media-library.media_model', CapellMedia::class);
});

function createGlobalMediaAdminUser(): Authenticatable
{
    $user = new class extends Authenticatable implements FilamentUser
    {
        /** @use HasFactory<Factory<self>> */
        use HasFactory;

        protected $table = 'users';

        public function canAccessPanel(Panel $panel): bool
        {
            return true;
        }

        public function isGlobalAdmin(): bool
        {
            return true;
        }

        public function hasRole(string $role): bool
        {
            return true;
        }

        /** @return Collection<int, int> */
        public function getAssignedSiteIds(): Collection
        {
            return collect();
        }
    };

    $user->forceFill([
        'name' => 'Media Admin',
        'email' => fake()->unique()->safeEmail(),
        'password' => bcrypt('password'),
    ])->save();

    Relation::morphMap(['test-media-admin-user' => $user::class], merge: true);

    return $user;
}

function createEditableImageMedia(): CapellMedia
{
    $owner = Page::factory()->state(['name' => 'Media owner'])->create();

    /** @var CapellMedia $media */
    $media = $owner
        ->addMedia(UploadedFile::fake()->image('hero.jpg', 1200, 800))
        ->usingName('Hero')
        ->toMediaCollection('image');

    return $media;
}

it('loads focal point and crop preset state for an image', function (): void {
    $media = createEditableImageMedia();
    $page = Page::factory()->state(['name' => 'Landing Page'])->create();

    AssetAttachment::query()->create([
        'related_type' => $page->getMorphClass(),
        'related_id' => $page->getKey(),
        'asset_type' => $media->getMorphClass(),
        'asset_id' => $media->getKey(),
        'order' => 1,
    ]);

    Livewire::test(EditMedia::class, [
        'record' => $media->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSchemaStateSet([
            'name' => 'Hero',
            'focal_point_x' => 50,
            'focal_point_y' => 50,
            'crop_presets' => ['thumbnail', 'card', 'hero', 'open_graph'],
        ])
        ->assertSee('50%, 50%')
        ->assertSee('Hero')
        ->assertSee(__('capell-admin::media.file_description'))
        ->assertSee(__('capell-admin::media.external_video_description'))
        ->assertSee('Used on')
        ->assertSee('Landing Page');
});

it('hides media usage edit urls when the actor cannot edit related records', function (): void {
    Route::get('/admin/pages/{record}/edit', fn (): string => '')
        ->name('filament.admin.resources.pages.edit');

    $media = createEditableImageMedia();
    $page = Page::factory()->state(['name' => 'Landing Page'])->create();

    AssetAttachment::query()->create([
        'related_type' => $page->getMorphClass(),
        'related_id' => $page->getKey(),
        'asset_type' => $media->getMorphClass(),
        'asset_id' => $media->getKey(),
        'order' => 1,
    ]);

    $adminItems = BuildMediaUsageItemsAction::run($media);

    expect($adminItems)->toHaveCount(2)
        ->and($adminItems[0]['url'])->not->toBeNull()
        ->and($adminItems[1]['url'])->not->toBeNull();

    test()->actingAsUser();

    $media->refresh();

    $limitedItems = BuildMediaUsageItemsAction::run($media);

    expect($limitedItems)->toHaveCount(2)
        ->and($limitedItems[0]['url'])->toBeNull()
        ->and($limitedItems[1]['url'])->toBeNull();
});

it('saves focal point crop presets and localized metadata', function (): void {
    $media = createEditableImageMedia();
    $language = Language::factory()->english()->create();

    $fileManipulator = new class extends FileManipulator
    {
        /** @var list<array{media: SpatieMedia, names: array<int, string>, responsive: bool}> */
        public array $calls = [];

        /**
         * @param  array<int, string>  $onlyConversionNames
         */
        public function createDerivedFiles(
            SpatieMedia $media,
            array $onlyConversionNames = [],
            bool $onlyMissing = false,
            bool $withResponsiveImages = false,
            bool $queueAll = false,
        ): void {
            $this->calls[] = [
                'media' => $media,
                'names' => $onlyConversionNames,
                'responsive' => $withResponsiveImages,
            ];
        }
    };

    app()->instance(FileManipulator::class, $fileManipulator);

    Livewire::test(EditMedia::class, [
        'record' => $media->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Homepage hero',
            'focal_point_x' => 32,
            'focal_point_y' => 68,
            'crop_presets' => ['thumbnail', 'hero'],
            'translations' => [
                [
                    'language_id' => $language->getKey(),
                    'title' => 'Homepage hero',
                    'meta' => [
                        'alt' => 'Children running through a splash park',
                        'caption' => 'Summer campaign hero image',
                        'credit' => 'Capell Studio',
                        'decorative' => false,
                    ],
                ],
            ],
        ])
        ->assertSee(__('capell-admin::media.alt_text_helper'))
        ->assertSee(__('capell-admin::media.caption_helper'))
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $media->refresh();
    $translation = Translation::query()
        ->where('language_id', $language->getKey())
        ->where('translatable_type', $media->getMorphClass())
        ->where('translatable_id', $media->getKey())
        ->firstOrFail();

    expect($media->name)
        ->toBe('Homepage hero')
        ->and($media->getFocalPoint())
        ->toBe(['x' => 32, 'y' => 68])
        ->and($media->getCropPresetNames())
        ->toBe(['thumbnail', 'hero'])
        ->and($translation->title)
        ->toBe('Homepage hero')
        ->and($translation->meta)
        ->toMatchArray([
            'alt' => 'Children running through a splash park',
            'caption' => 'Summer campaign hero image',
            'credit' => 'Capell Studio',
        ])
        ->and($fileManipulator->calls)
        ->toHaveCount(1)
        ->and($fileManipulator->calls[0]['names'])
        ->toBe(['thumbnail', 'hero'])
        ->and($fileManipulator->calls[0]['responsive'])
        ->toBeTrue();
});

it('requires alt text for non-decorative localized metadata', function (): void {
    $media = createEditableImageMedia();
    $language = Language::factory()->english()->create();

    Livewire::test(EditMedia::class, [
        'record' => $media->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Homepage hero',
            'focal_point_x' => 50,
            'focal_point_y' => 50,
            'crop_presets' => ['thumbnail'],
            'translations' => [
                [
                    'language_id' => $language->getKey(),
                    'title' => 'Homepage hero',
                    'meta' => [
                        'alt' => '',
                        'decorative' => false,
                    ],
                ],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors(['translations.0.meta.alt' => 'required']);
});

it('allows empty alt text when localized metadata is decorative', function (): void {
    $media = createEditableImageMedia();
    $language = Language::factory()->english()->create();

    Livewire::test(EditMedia::class, [
        'record' => $media->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Decorative divider',
            'focal_point_x' => 50,
            'focal_point_y' => 50,
            'crop_presets' => ['thumbnail'],
            'translations' => [
                [
                    'language_id' => $language->getKey(),
                    'title' => 'Decorative divider',
                    'meta' => [
                        'alt' => '',
                        'decorative' => true,
                    ],
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('allows installed packages to contribute media edit actions', function (): void {
    $media = createEditableImageMedia();
    $spy = new stdClass;
    $spy->called = false;

    app()->bind('test-media-edit-action-extender', fn (): MediaEditActionExtender => new readonly class($spy) implements MediaEditActionExtender
    {
        public function __construct(private stdClass $spy) {}

        public function getHeaderActions(EditMedia $page): array
        {
            return [
                Action::make('doctor-image')
                    ->label('Doctor image')
                    ->action(function (): void {
                        $this->spy->called = true;
                    }),
            ];
        }
    });

    app()->tag(['test-media-edit-action-extender'], MediaEditActionExtender::TAG);

    Livewire::test(EditMedia::class, [
        'record' => $media->getRouteKey(),
    ])
        ->assertSuccessful()
        ->callAction('doctor-image')
        ->assertHasNoActionErrors();

    expect($spy->called)->toBeTrue();
});

it('leaves cropping to curator when curator is the configured backend', function (): void {
    config()->set('capell.media.backend', 'curator');

    $media = createEditableImageMedia();

    Livewire::test(EditMedia::class, [
        'record' => $media->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertDontSee(__('capell-admin::media.crop_heading'))
        ->assertSee(__('capell-admin::media.localized_metadata_heading'));
});

it('can save localized metadata when curator owns image cropping', function (): void {
    config()->set('capell.media.backend', 'curator');

    $media = createEditableImageMedia();
    $language = Language::query()->firstOrFail();

    Livewire::test(EditMedia::class, [
        'record' => $media->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Curator managed image',
            'translations' => [
                [
                    'language_id' => $language->getKey(),
                    'title' => 'Curator image',
                    'meta' => [
                        'alt' => 'Curator owned crop alt text',
                        'caption' => 'Curator owned crop caption',
                        'credit' => 'Capell',
                        'decorative' => false,
                    ],
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($media->refresh()->name)->toBe('Curator managed image')
        ->and($media->getAltText($language))->toBe('Curator owned crop alt text');
});

it('saves YouTube video metadata from the media edit form', function (): void {
    $media = createEditableImageMedia();

    Livewire::test(EditMedia::class, [
        'record' => $media->getRouteKey(),
    ])
        ->assertSuccessful()
        ->fillForm([
            'name' => 'Tour video',
            'external_video_url' => 'https://www.youtube.com/watch?v=FgalLC99jzY',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $media->refresh();

    expect($media->name)->toBe('Tour video')
        ->and($media->collection_name)->toBe('video')
        ->and($media->mime_type)->toBe('video/youtube')
        ->and($media->externalVideo()?->provider)->toBe('youtube')
        ->and($media->externalVideo()?->embedUrl)->toBe('https://www.youtube-nocookie.com/embed/FgalLC99jzY?enablejsapi=1&rel=0&playsinline=1');
});
