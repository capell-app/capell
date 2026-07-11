<?php

declare(strict_types=1);

use Capell\Admin\Actions\GetFlatComponentKeysAction;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;

it('flattens nested components to keys', function (): void {
    $child = test()->createMock(Field::class);
    $child->method('isDehydrated')->willReturn(true);
    $child->method('getStatePath')->with(false)->willReturn('b');
    $child->method('getChildComponents')->willReturn([]);

    $parent = test()->createMock(Component::class);
    $parent->method('isDehydrated')->willReturn(true);
    $parent->method('getStatePath')->with(false)->willReturn('a');
    $parent->method('getChildComponents')->willReturn([$child]);

    $keys = GetFlatComponentKeysAction::run($parent);

    expect($keys)->toHaveKey('b');
});

it('returns empty for no components', function (): void {
    $component = test()->createMock(Field::class);
    $component->method('getChildComponents')->willReturn([]);
    $component->method('isDehydrated')->willReturn(false);
    $component->method('getStatePath')->with(false)->willReturn('');

    expect(GetFlatComponentKeysAction::run($component))->toBeArray()->toBeEmpty();
});
