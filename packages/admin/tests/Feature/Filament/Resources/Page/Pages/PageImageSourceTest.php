<?php

declare(strict_types=1);

use Capell\Admin\Contracts\Media\AdminMediaFieldFactory;
use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Forms\Components\Hidden;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(CreatesAdminUser::class)->group('page');

beforeEach(function (): void {
    $this->actingAsAdmin();
    Storage::fake('public');

    // Exercise the ID-based adapter boundary, including Curator's item-array
    // hydration/dehydration, without requiring the optional media package.
    app()->bind(AdminMediaFieldFactory::class, static fn (): AdminMediaFieldFactory => new class implements AdminMediaFieldFactory
    {
        #[Override]
        public function make(string $name): Hidden
        {
            return Hidden::make($name . '_id')
                ->afterStateHydrated(static function (Hidden $component, mixed $state): void {
                    $component->state(filled($state) ? [(string) Str::uuid() => ['id' => $state]] : []);
                })
                ->dehydrateStateUsing(static fn (mixed $state): mixed => collect($state)->pluck('id')->first())
                ->dehydrated(static fn (mixed $state): bool => filled($state));
        }
    });
});

it('persists a chosen page image reference and hydrates it when the editor reloads', function (): void {
    $page = Page::factory()->withTranslations()->createOne();
    $media = Media::factory()->model($page->site)->createOne();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm([
            'meta.image_source.type' => 'media',
            'meta.image_id' => [(string) Str::uuid() => ['id' => $media->getKey()]],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->meta)->toMatchArray([
        'image_source' => ['type' => 'media'],
        'image_id' => $media->getKey(),
    ]);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertFormSet(static function (array $state) use ($media): array {
            expect(collect(data_get($state, 'meta.image_id'))->pluck('id')->all())->toBe([$media->getKey()]);

            return ['meta.image_source.type' => 'media'];
        })
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($page->refresh()->meta, 'image_id'))->toBe($media->getKey());
});

it('allows two pages to reference the same media item without moving its ownership', function (): void {
    $pages = Page::factory()->withTranslations()->count(2)->create();
    $media = Media::factory()->model($pages->firstOrFail()->site)->createOne();
    $owner = $media->only(['model_type', 'model_id']);

    foreach ($pages as $page) {
        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->fillForm([
                'meta.image_source.type' => 'media',
                'meta.image_id' => [(string) Str::uuid() => ['id' => $media->getKey()]],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(data_get($page->refresh()->meta, 'image_id'))->toBe($media->getKey());
    }

    expect($media->refresh()->only(['model_type', 'model_id']))->toBe($owner);
});

it('round trips existing page image source data', function (array $source): void {
    Storage::disk('public')->put('capell/image-sources/example.jpg', 'existing image');

    $page = Page::factory()->withTranslations()->createOne([
        'meta' => ['image_source' => $source],
    ]);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    // MySQL JSON columns do not preserve key order.
    expect(data_get($page->refresh()->meta, 'image_source'))->toEqual($source);
})->with([
    'media' => [['type' => 'media']],
    'url' => [['type' => 'url', 'url' => 'https://images.unsplash.com/example.jpg']],
    'upload' => [['type' => 'upload', 'path' => 'capell/image-sources/example.jpg']],
]);

it('round trips a legacy image reference without a source descriptor', function (): void {
    $page = Page::factory()->withTranslations()->createOne(['meta' => ['image_id' => 123]]);

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->assertFormSet(static function (array $state): array {
            expect(collect(data_get($state, 'meta.image_id'))->pluck('id')->all())->toBe([123]);

            return [];
        })
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($page->refresh()->meta, 'image_id'))->toBe(123);
});

it('can save a page with no image selected', function (): void {
    $page = Page::factory()->withTranslations()->createOne();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($page->refresh()->meta, 'image_id'))->toBeNull();
});

it('continues to reject an unsupported literal none source', function (): void {
    $page = Page::factory()->withTranslations()->createOne();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->fillForm(['meta.image_source.type' => 'none'])
        ->call('save')
        ->assertHasFormErrors(['meta.image_source.type']);
});
