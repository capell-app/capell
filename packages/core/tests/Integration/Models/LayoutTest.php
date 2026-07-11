<?php

declare(strict_types=1);

use Capell\Core\Contracts\Media\MediaContract as Media;
use Capell\Core\Database\Factories\MediaFactory;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Layout;
use Capell\Core\Support\Creator\LayoutCreator;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('can save meta->image', function (): void {
    $layout = Layout::factory()->createOne();

    $media = MediaFactory::new([
        'model_type' => resolve(Layout::class)->getMorphClass(),
        'model_id' => $layout->id,
        'collection_name' => MediaCollectionEnum::Image,
        'order_column' => 1,
    ])
        ->createOne();

    expect($layout->refresh())
        ->image->toBeInstanceOf(Media::class)
        ->and($layout->image->id)->toBe($media->id);
});

it('can get groups', function (): void {
    Layout::factory()
        ->count(3)
        ->sequence(
            ['group' => 'first'],
            ['group' => 'second'],
            ['group' => 'first'],
        )
        ->create();

    expect(Layout::getGroups())
        ->toBe([
            'first' => 'first (2)',
            'second' => 'second (1)',
        ]);
});

it('can be sorted', function (): void {
    Layout::factory()
        ->count(3)
        ->sequence(
            ['order' => 2],
            ['order' => 1],
            ['order' => 3],
        )
        ->create();

    expect(Layout::query()->ordered()->pluck('order')->all())
        ->toBe([1, 2, 3]);
});

it('stores layout element keys', function (): void {
    $layout = Layout::factory()->createOne([
        'elements' => ['first-element', 'second-element'],
        'containers' => [
            'main' => [
                'elements' => [
                    ['element_key' => 'first-element'],
                    ['element_key' => 'second-element'],
                ],
            ],
        ],
    ]);

    expect($layout->elements)
        ->toBe(['first-element', 'second-element']);
});

it('computes widget keys from containers without persisting a widgets column', function (): void {
    $layout = Layout::factory()->createOne([
        'containers' => [
            'main' => [
                'widgets' => [
                    ['widget_key' => 'hero'],
                    ['widget_key' => 'cta'],
                    ['widget_key' => 'hero'],
                ],
            ],
            'aside' => [
                'widgets' => [
                    'newsletter',
                    ['widget_key' => null],
                ],
            ],
        ],
    ]);

    expect(fn () => $layout->fill(['widgets' => ['should-not-save']]))
        ->toThrow(MassAssignmentException::class)
        ->and($layout->refresh()->widgets)
        ->toBe(['hero', 'cta', 'newsletter'])
        ->and($layout->getAttributes())->not->toHaveKey('widgets');
});

it('does not seed theme-owned element keys when creating the default layout', function (): void {
    $layout = resolve(LayoutCreator::class)->createDefaultLayout();

    expect($layout->refresh())
        ->elements->toBeNull();
});
