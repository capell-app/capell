<?php

declare(strict_types=1);

use Capell\Core\Actions\ColorConverterAction;

it('converts hex to rgb', function (): void {
    $rgb = ColorConverterAction::run('#FF0000', 'rgb');

    expect($rgb)->toBe('rgb(255, 0, 0)');
});

it('throws on invalid format', function (): void {
    expect(fn () => ColorConverterAction::run('not-a-color', 'rgb'))
        ->toThrow(InvalidArgumentException::class);
});
