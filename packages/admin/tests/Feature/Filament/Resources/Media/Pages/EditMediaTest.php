<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\BuildMediaUsageItemsAction;
use Capell\Admin\Actions\Media\CountOmittedMediaUsageAction;
use Capell\Admin\Actions\Media\CreateExternalVideoMediaAction;
use Capell\Admin\Actions\Media\UpdateMediaAction;
use Capell\Admin\Contracts\Extenders\MediaEditActionExtender;
use Capell\Admin\Filament\Resources\Media\Pages\EditMedia;
use Capell\Admin\Filament\Resources\Media\Pages\ListMedia;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeKind;
use Capell\Core\Enums\ContentGraph\ContentGraphEdgeStrength;
use Capell\Core\Models\AssetAttachment;
use Capell\Core\Models\ContentGraphEdge;
use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Media as CapellMedia;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Media\YouTubeVideoUrl;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\Action;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

function createEditableDocumentMedia(): CapellMedia
{
    $owner = Page::factory()->createOne();

    /** @var CapellMedia $media */
    $media = $owner
        ->addMedia(UploadedFile::fake()->create('brochure.pdf', 100, 'application/pdf'))
        ->usingName('Brochure')
        ->toMediaCollection('uploads');

    return $media;
}

it('shows shared media impact before the media item is saved', function (): void {
    test()->actingAsAdmin();

    $language = Language::factory()->english()->createOne();
    $site = Site::factory()
        ->language($language)
        ->withTranslations($language, siteDomainData: [
            'domain' => 'example.test',
            'scheme' => 'http',
            'path' => null,
        ])
        ->createOne();
    $layout = Layout::factory()->site($site)->createOne(['name' => 'Shared layout']);
    $page = Page::factory()->site($site)->layout($layout)->createOne(['name' => 'Shared landing']);
    PageUrl::factory()->page($page)->site($site)->language($language)->createOne(['url' => '/landing']);
    $media = CapellMedia::factory()->model($page)->createOne(['name' => 'Shared hero']);

    ContentGraphEdge::query()->create([
        'source_type' => Page::class,
        'source_id' => $page->getKey(),
        'target_type' => CapellMedia::class,
        'target_id' => $media->getKey(),
        'kind' => ContentGraphEdgeKind::UsesMedia,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
    ]);
    ContentGraphEdge::query()->create([
        'source_type' => Layout::class,
        'source_id' => $layout->getKey(),
        'target_type' => CapellMedia::class,
        'target_id' => $media->getKey(),
        'kind' => ContentGraphEdgeKind::UsesMedia,
        'strength' => ContentGraphEdgeStrength::Strong,
        'source_package' => 'capell-app/core',
    ]);

    Livewire::test(EditMedia::class, [
        'record' => $media->getRouteKey(),
    ])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::media.impact_preview'))
        ->assertSee(__('capell-admin::media.impact_preview_exact'))
        ->assertSee('Shared landing')
        ->assertSee('Shared layout')
        ->assertSee($site->name)
        ->assertSee('http://example.test/landing')
        ->assertSee(__('capell-core::generic.content_impact_consequence_strong'));
});

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
        ->assertDontSee(__('capell-admin::media.external_video_description'))
        ->assertSee('Used on')
        ->assertSee('Landing Page');
});

it('omits inaccessible media usage relations', function (): void {
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

    expect($limitedItems)
        ->toHaveCount(1)
        ->and($limitedItems[0]['url'])->toBeNull()
        ->and(collect($limitedItems)->pluck('title')->all())
        ->not->toContain($page->name);
});

it('shows how many tracked usages are omitted and reveals all of them on request', function (): void {
    $media = createEditableImageMedia();

    foreach (range(1, 14) as $position) {
        AssetAttachment::query()->create([
            'related_type' => Page::query()->getModel()->getMorphClass(),
            'related_id' => Page::factory()->state(['name' => 'Usage page ' . $position])->create()->getKey(),
            'asset_type' => $media->getMorphClass(),
            'asset_id' => $media->getKey(),
            'order' => $position,
        ]);
    }

    expect(BuildMediaUsageItemsAction::run($media))->toHaveCount(13)
        ->and(BuildMediaUsageItemsAction::run($media, showAll: true))->toHaveCount(15)
        ->and(CountOmittedMediaUsageAction::run($media))->toBe(2);

    Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->assertSee('Usage page 12')
        ->assertDontSee('Usage page 13')
        ->assertSee(trans_choice('capell-admin::media.usage_omitted', 2, ['count' => 2]))
        ->call('showAllUsage')
        ->assertSee('Usage page 14')
        ->assertDontSee(trans_choice('capell-admin::media.usage_omitted', 2, ['count' => 2]));
});

it('shows no omitted-usage notice when every tracked usage is listed', function (): void {
    $media = createEditableImageMedia();

    expect(CountOmittedMediaUsageAction::run($media))->toBe(0)
        ->and(CountOmittedMediaUsageAction::run(null))->toBe(0);
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
        ->toEqualCanonicalizing(['thumbnail', 'hero'])
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
        ->toEqualCanonicalizing(['thumbnail', 'hero'])
        ->and($fileManipulator->calls[0]['responsive'])
        ->toBeTrue();
});

it('shows alt text and rights credit fields before adding locale metadata', function (): void {
    $media = createEditableImageMedia();

    expect($media->translations()->exists())->toBeFalse();

    Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::media.alt_text'))
        ->assertSee(__('capell-admin::media.credit'));
});

it('saves the visible alt text per language and clears the missing metadata flags', function (): void {
    $media = createEditableImageMedia();
    $english = Language::query()->default()->firstOrFail();
    $french = Language::factory()->french()->createOne();

    Livewire::test(ListMedia::class)
        ->assertTableColumnStateSet('health_state', 'missing_alt', $media)
        ->filterTable('health', 'missing_alt')
        ->assertCanSeeTableRecords([$media]);

    $editor = Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->assertSuccessful();

    $translations = $editor->get('data.translations');
    expect($translations)->toHaveCount(1);
    $rowKey = array_key_first($translations);

    $editor
        ->assertFormFieldExists(sprintf('translations.%s.meta.alt', $rowKey))
        ->assertFormFieldExists(sprintf('translations.%s.meta.credit', $rowKey))
        ->fillForm([
            'translations' => [
                $rowKey => [
                    'language_id' => $english->getKey(),
                    'meta' => ['alt' => 'Children playing in a park', 'credit' => 'Park Studio'],
                ],
                'french' => [
                    'language_id' => $french->getKey(),
                    'meta' => ['alt' => 'Des enfants jouent dans un parc', 'credit' => 'Studio du parc'],
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $media->refresh();
    expect($media->getAltText($english))->toBe('Children playing in a park')
        ->and($media->getAltText($french))->toBe('Des enfants jouent dans un parc')
        ->and($media->getCredit($english))->toBe('Park Studio')
        ->and($media->getCredit($french))->toBe('Studio du parc');

    $reopened = Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()]);
    $savedTranslations = collect($reopened->get('data.translations'))->keyBy('language_id');
    expect($savedTranslations->get($english->getKey())['meta'])->toMatchArray([
        'alt' => 'Children playing in a park',
        'credit' => 'Park Studio',
    ])->and($savedTranslations->get($french->getKey())['meta'])->toMatchArray([
        'alt' => 'Des enfants jouent dans un parc',
        'credit' => 'Studio du parc',
    ]);

    Livewire::test(ListMedia::class)
        ->assertTableColumnStateSet('health_state', 'unused', $media)
        ->filterTable('health', 'missing_alt')
        ->assertCanNotSeeTableRecords([$media])
        ->resetTableFilters()
        ->filterTable('health', 'missing_rights')
        ->assertCanNotSeeTableRecords([$media]);
});

it('does not save an untouched initial locale metadata row when renaming media', function (): void {
    $media = createEditableImageMedia();

    Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->fillForm(['name' => 'Renamed hero'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($media->refresh()->name)->toBe('Renamed hero')
        ->and($media->translations()->exists())->toBeFalse();
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

it('saves document metadata per language without image alt text or decorative controls', function (): void {
    $media = createEditableDocumentMedia();
    $english = Language::query()->default()->firstOrFail();
    $french = Language::factory()->french()->createOne();

    expect($media->isImage())->toBeFalse();

    $editor = Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->assertSuccessful();
    $rowKey = array_key_first($editor->get('data.translations'));

    $editor
        ->fillForm([
            'name' => 'Visitor brochure',
            'translations' => [
                $rowKey => [
                    'language_id' => $english->getKey(),
                    'title' => 'Visitor brochure',
                    'meta' => ['alt' => '', 'caption' => 'Plan your visit', 'credit' => 'Visitor Services', 'decorative' => false],
                ],
                'french' => [
                    'language_id' => $french->getKey(),
                    'title' => 'Brochure de visite',
                    'meta' => ['alt' => '', 'caption' => 'Préparez votre visite', 'credit' => 'Office de tourisme', 'decorative' => false],
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertFormFieldIsHidden(sprintf('translations.%s.meta.alt', $rowKey))
        ->assertFormFieldIsHidden(sprintf('translations.%s.meta.decorative', $rowKey))
        ->assertNotified();

    expect($media->refresh()->name)->toBe('Visitor brochure');
    $translations = $media->translations()->get()->keyBy('language_id');

    $englishTranslation = $translations->sole('language_id', $english->getKey());
    $frenchTranslation = $translations->sole('language_id', $french->getKey());

    expect($translations)->toHaveCount(2)
        ->and($englishTranslation->title)->toBe('Visitor brochure')
        ->and($englishTranslation->meta)->toEqual([
            'caption' => 'Plan your visit',
            'credit' => 'Visitor Services',
        ])
        ->and($frenchTranslation->title)->toBe('Brochure de visite')
        ->and($frenchTranslation->meta)->toEqual([
            'caption' => 'Préparez votre visite',
            'credit' => 'Office de tourisme',
        ]);
});

it('preserves historic document alt and decorative metadata during unrelated edits', function (): void {
    $media = createEditableDocumentMedia();
    $english = Language::query()->default()->firstOrFail();
    $french = Language::factory()->french()->createOne();

    foreach ([$english, $french] as $language) {
        $media->translations()->create([
            'language_id' => $language->getKey(),
            'title' => 'Historic brochure',
            'meta' => ['alt' => 'Historic description ' . $language->locale, 'caption' => 'Historic caption', 'credit' => 'Historic credit', 'decorative' => true],
        ]);
    }

    $editor = Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()]);
    $translations = $editor->get('data.translations');
    foreach ($translations as $rowKey => $translation) {
        $translations[$rowKey]['title'] = 'Updated brochure';
        $translations[$rowKey]['meta']['credit'] = 'Updated credit';
    }

    $editor->fillForm(['translations' => $translations])
        ->call('save')
        ->assertHasNoFormErrors();

    foreach ([$english, $french] as $language) {
        $translation = $media->translations()->where('language_id', $language->getKey())->firstOrFail();
        expect($translation->title)->toBe('Updated brochure')
            ->and($translation->meta)->toMatchArray([
                'alt' => 'Historic description ' . $language->locale,
                'caption' => 'Historic caption',
                'credit' => 'Updated credit',
                'decorative' => true,
            ]);
    }
});

it('denies media metadata editing to an unauthorised actor', function (bool $image): void {
    $media = $image ? createEditableImageMedia() : createEditableDocumentMedia();
    $originalName = $media->name;
    $owner = $media->model;
    assert($owner instanceof Page);
    $actor = test()->createUser();
    // Resolve an accessible record, then exercise the edit-permission denial.
    $actor->assignedSiteIds = collect([(int) $owner->site_id]);

    test()->actingAs($actor);

    Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->assertForbidden();

    expect($media->refresh()->name)->toBe($originalName)
        ->and($media->translations()->exists())->toBeFalse();
})->with([false, true]);

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

    expect($media->refresh()->isDecorative($language))->toBeTrue()
        ->and($media->getAltText($language))->toBe('');
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

it('keeps an uploaded image file-backed when a YouTube URL is submitted', function (): void {
    $media = createEditableImageMedia();
    $original = $media->only(['collection_name', 'mime_type', 'file_name', 'size']);

    Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->assertSuccessful()
        ->assertDontSee(__('capell-admin::media.external_video_description'))
        ->fillForm(['name' => 'Renamed hero'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(fn (): mixed => UpdateMediaAction::run($media->refresh(), [
        'name' => 'Tour video',
        'focal_point_x' => 50,
        'focal_point_y' => 50,
        'crop_presets' => ['thumbnail'],
        'external_video_url' => 'https://www.youtube.com/watch?v=FgalLC99jzY',
    ]))->toThrow(ValidationException::class, __('capell-admin::media.external_video_file_backed'));

    $media->refresh();

    expect($media->name)->toBe('Renamed hero')
        ->and($media->only(['collection_name', 'mime_type', 'file_name', 'size']))->toBe($original)
        ->and($media->isExternalVideo())->toBeFalse();
});

it('changes the YouTube source of an external video and refuses to clear it', function (): void {
    $site = Site::factory()->createOne();
    $video = CreateExternalVideoMediaAction::run($site, 'Tour video', expectPresent(YouTubeVideoUrl::parse('https://youtu.be/FgalLC99jzY')));
    $video->setCustomProperty('kept', 'yes')->save();

    Livewire::test(EditMedia::class, ['record' => $video->getRouteKey()])
        ->assertSuccessful()
        ->assertSee(__('capell-admin::media.external_video_description'))
        ->assertSchemaStateSet(['external_video_url' => 'https://youtu.be/FgalLC99jzY'])
        ->fillForm(['external_video_url' => 'https://youtu.be/dQw4w9WgXcQ'])
        ->call('save')
        ->assertHasNoFormErrors();

    $video->refresh();

    expect($video->externalVideo()?->videoId)->toBe('dQw4w9WgXcQ')
        ->and($video->file_name)->toBe('dQw4w9WgXcQ.youtube')
        ->and($video->mime_type)->toBe('video/youtube')
        ->and($video->getCustomProperty('kept'))->toBe('yes');

    Livewire::test(EditMedia::class, ['record' => $video->getRouteKey()])
        ->fillForm(['external_video_url' => ''])
        ->call('save')
        ->assertHasFormErrors(['external_video_url' => 'required']);

    expect($video->refresh()->externalVideo()?->videoId)->toBe('dQw4w9WgXcQ');
});

it('preserves an explicitly cleared crop preset selection across reopening and unrelated saves', function (): void {
    $media = createEditableImageMedia();

    Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->assertSchemaStateSet(['crop_presets' => ['thumbnail', 'card', 'hero', 'open_graph']])
        ->fillForm(['crop_presets' => []])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($media->refresh()->hasCropPresetSelection())->toBeTrue()
        ->and($media->getCropPresetNames())->toBe([]);

    Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->assertSchemaStateSet(['crop_presets' => []])
        ->fillForm(['name' => 'Renamed again'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($media->refresh()->name)->toBe('Renamed again')
        ->and($media->getCropPresetNames())->toBe([]);

    Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->fillForm(['crop_presets' => ['hero']])
        ->call('save');

    expect($media->refresh()->getCropPresetNames())->toBe(['hero']);
});

it('rolls back an image save with clear feedback when conversion fails and succeeds on retry', function (): void {
    $media = createEditableImageMedia();
    $media->setCropPresets(['thumbnail'])->setFocalPoint(50, 50)->save();

    $manipulator = new class extends FileManipulator
    {
        public bool $fail = true;

        /** @var list<array<int, string>> */
        public array $calls = [];

        /** @param array<int, string> $onlyConversionNames */
        #[Override]
        public function createDerivedFiles(
            SpatieMedia $media,
            array $onlyConversionNames = [],
            bool $onlyMissing = false,
            bool $withResponsiveImages = false,
            bool $queueAll = false,
        ): void {
            $this->calls[] = $onlyConversionNames;

            throw_if($this->fail && in_array('hero', $onlyConversionNames, true), RuntimeException::class, 'conversion exploded');
        }
    };
    app()->instance(FileManipulator::class, $manipulator);

    $component = Livewire::test(EditMedia::class, ['record' => $media->getRouteKey()])
        ->fillForm([
            'name' => 'Should not stick',
            'focal_point_x' => 10,
            'focal_point_y' => 90,
            'crop_presets' => ['thumbnail', 'hero'],
        ])
        ->call('save')
        ->assertNotified(Notification::make()
            ->title(__('capell-admin::media.save_failed'))
            ->body(__('capell-admin::media.derivatives_failed'))
            ->danger()
            ->persistent());

    $media->refresh();

    expect($media->name)->toBe('Hero')
        ->and($media->getFocalPoint())->toBe(['x' => 50, 'y' => 50])
        ->and($media->getCropPresetNames())->toBe(['thumbnail'])
        // MySQL JSON columns reorder object keys, so each call's preset order is storage-defined.
        ->and(array_map(fn (array $names): array => collect($names)->sort()->values()->all(), $manipulator->calls))
        ->toBe([['hero', 'thumbnail'], ['thumbnail']]);

    $manipulator->fail = false;
    $component->call('save')->assertHasNoFormErrors();

    $media->refresh();

    expect($media->name)->toBe('Should not stick')
        ->and($media->getFocalPoint())->toBe(['x' => 10, 'y' => 90])
        ->and($media->getCropPresetNames())->toEqualCanonicalizing(['thumbnail', 'hero']);
});
