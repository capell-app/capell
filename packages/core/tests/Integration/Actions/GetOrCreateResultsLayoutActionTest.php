<?php

declare(strict_types=1);

use Capell\Core\Actions\GetOrCreateResultsLayoutAction;
use Capell\Core\Enums\LayoutEnum;
use Capell\Core\Models\Layout;
use Capell\Core\Support\Creator\LayoutCreator;

it('creates the results layout without requiring element state', function (): void {
    expect(Layout::query()->where('key', LayoutEnum::Results->value)->exists())->toBeFalse();

    $layout = GetOrCreateResultsLayoutAction::run();

    expect($layout->key)->toBe(LayoutEnum::Results->value)
        ->and($layout->containers)->toBeNull()
        ->and($layout->elements)->toBeNull();
});

it('does not overwrite an existing customized results layout', function (): void {
    $layout = resolve(LayoutCreator::class)->createResultsLayout();
    $layout->update([
        'containers' => [
            'custom' => [
                'elements' => [
                    ['element_key' => 'custom-widget'],
                ],
            ],
        ],
        'elements' => ['custom-widget'],
    ]);

    GetOrCreateResultsLayoutAction::run();

    expect($layout->refresh()->containers)->toHaveKey('custom')
        ->and($layout->elements)->toBe(['custom-widget']);
});

it('does not overwrite an existing empty results layout', function (): void {
    $layout = resolve(LayoutCreator::class)->createResultsLayout();
    $layout->update([
        'containers' => [],
        'elements' => [],
    ]);

    $result = GetOrCreateResultsLayoutAction::run();

    expect($result->is($layout))->toBeTrue()
        ->and($layout->refresh()->containers)->toBe([])
        ->and($layout->elements)->toBe([]);
});
