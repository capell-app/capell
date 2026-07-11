<?php

declare(strict_types=1);

use Capell\Core\Actions\ColorTypeDetectorAction;

it('detects color type', function (): void {
    expect(ColorTypeDetectorAction::run('#fff'))->toBe('hex');
    expect(ColorTypeDetectorAction::run('rgb(0,0,0)'))->toBe('rgba');
});

it('returns null or throws on invalid', function (): void {
    expect(fn () => ColorTypeDetectorAction::run('nope'))
        ->toThrow(InvalidArgumentException::class);
});
