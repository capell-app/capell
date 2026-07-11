<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\Pages\Pages\EditPage;
use Capell\Core\Models\Page;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Livewire\Livewire;

uses(CreatesAdminUser::class)
    ->group('page');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('saves the page image source from the editor sidebar context', function (): void {
    $page = Page::factory()
        ->withTranslations()
        ->createOne([
            'meta' => [
                'image_source' => [
                    'type' => 'url',
                    'url' => 'https://images.unsplash.com/photo-1497366754035-f200968a6e72',
                ],
            ],
        ]);

    Livewire::test(EditPage::class, [
        'record' => $page->getRouteKey(),
    ])
        ->assertSchemaStateSet(function (array $state): array {
            expect(data_get($state, 'meta.image_source.type'))->toBe('url')
                ->and(data_get($state, 'meta.image_source.url'))->toBe('https://images.unsplash.com/photo-1497366754035-f200968a6e72');

            return [];
        })
        ->fillForm([
            'meta' => [
                'image_source' => [
                    'type' => 'url',
                    'url' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee',
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($page->refresh()->meta, 'image_source'))->toMatchArray([
        'type' => 'url',
        'url' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee',
    ]);
});
