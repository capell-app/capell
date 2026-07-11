<?php

declare(strict_types=1);

use Capell\Admin\Filament\Resources\BlockTemplates\Pages\ManageBlockTemplates;
use Capell\Core\Models\BlockTemplate;
use Capell\Tests\Support\Concerns\CreatesAdminUser;
use Filament\Actions\CreateAction;
use Livewire\Livewire;

use function Pest\Laravel\assertDatabaseHas;

uses(CreatesAdminUser::class)
    ->group('block-template');

beforeEach(function (): void {
    test()->actingAsAdmin();
});

it('lists block templates', function (): void {
    $templates = BlockTemplate::factory()->count(3)->create();

    Livewire::test(ManageBlockTemplates::class)
        ->assertSuccessful()
        ->assertCountTableRecords(3)
        ->assertCanSeeTableRecords($templates);
});

it('creates block templates with builder block content', function (): void {
    Livewire::test(ManageBlockTemplates::class)
        ->assertSuccessful()
        ->callAction(CreateAction::class, data: [
            'name' => 'Hero Story',
            'key' => 'hero_story',
            'description' => 'Reusable hero starter',
            'enabled' => true,
            'blocks' => json_encode([
                [
                    'type' => 'content',
                    'data' => [
                        'content' => '<h2>Hero story</h2>',
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ])
        ->assertHasNoActionErrors();

    assertDatabaseHas(BlockTemplate::class, [
        'key' => 'hero_story',
        'name' => 'Hero Story',
        'enabled' => true,
    ]);

    expect(BlockTemplate::query()->where('key', 'hero_story')->sole()->blocks)->toBe([
        [
            'type' => 'content',
            'data' => [
                'content' => '<h2>Hero story</h2>',
            ],
        ],
    ]);
});
