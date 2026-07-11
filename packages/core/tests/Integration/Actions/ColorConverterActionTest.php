<?php

declare(strict_types=1);

use Capell\Core\Actions\ColorConverterAction;

it('returns null for null, empty and "0" inputs', function (?string $input): void {
    expect(ColorConverterAction::run($input))->toBeNull();
})->with([
    null,
    '',
    '0',
]);

it('normalizes multiple red formats to the same hex', function (string $input): void {
    expect(ColorConverterAction::run($input, 'hex'))->toBe('#ff0000');
})->with([
    '#ff0000',
    '#f00',
    'f00',
    'ff0000',
    'rgb(255,0,0)',
    'rgb(255 0 0)',
    'rgba(255,0,0,0.5)',
    'rgb(255 0 0 / 50%)',
    'rgb(100% 0% 0%)',
]);

it('converts rgb with slash alpha percent correctly (previous failing format)', function (): void {
    expect(ColorConverterAction::run('rgb(126 126 126 / 15%)', 'hex'))->toBe('#7e7e7e');
});

it('converts percent channels mixed with integers', function (): void {
    expect(ColorConverterAction::run('rgb(100% 0% 50%)', 'hex'))->toBe('#ff0080');
});

it('parses rgba(0,0,0,0.1) as black', function (): void {
    expect(ColorConverterAction::run('rgba(0,0,0,0.1)', 'hex'))->toBe('#000000');
});

it('produces hex output', function (): void {
    expect(ColorConverterAction::run('rgb(255, 0, 0)', 'hex'))->toBe('#ff0000');
});

it('produces an rgb string output', function (string $input, string $expected): void {
    expect(ColorConverterAction::run($input, 'rgb'))->toBe($expected);
})->with([
    ['#ff0000', 'rgb(255, 0, 0)'],
    ['#00ff00', 'rgb(0, 255, 0)'],
    ['#0000ff', 'rgb(0, 0, 255)'],
]);

it('produces an rgba string output', function (string $input, string $expected): void {
    expect(ColorConverterAction::run($input))->toBe($expected);
})->with([
    ['#ff0000', 'rgb(255, 0, 0)'],
    ['rgb(0,255,0)', 'rgb(0, 255, 0)'],
    ['rgba(0,0,255,1)', 'rgb(0, 0, 255)'],
    ['rgba(0,0,255,0.5)', 'rgba(0, 0, 255, 0.5)'],
]);

it('produces correct HSL for red', function (): void {
    expect(ColorConverterAction::run('#ff0000', 'hsl'))->toBe('hsl(0, 100.0%, 50.0%)');
});

it('produces correct HSL for black (achromatic)', function (): void {
    expect(ColorConverterAction::run('#000000', 'hsl'))->toBe('hsl(0, 0.0%, 0.0%)');
});

it('produces correct HSL for green and blue dominant colors', function (): void {
    expect(ColorConverterAction::run('#00ff00', 'hsl'))->toBe('hsl(120, 100.0%, 50.0%)')
        ->and(ColorConverterAction::run('#0000ff', 'hsl'))->toBe('hsl(240, 100.0%, 50.0%)');
});

it('produces OKLCH format string', function (): void {
    $oklch = ColorConverterAction::run('#ff0000', 'oklch');
    expect($oklch)->toStartWith('oklch(');
    expect($oklch)->toMatch('/oklch\\(.*\\)/');
});

it('normalises modern alpha values when producing rgba strings', function (): void {
    expect(ColorConverterAction::run('rgb(10 20 30 / -0.5)', 'rgba'))->toBe('rgba(10, 20, 30, 0)')
        ->and(ColorConverterAction::run('rgb(10 20 30 / 2)', 'rgba'))->toBe('rgb(10, 20, 30)')
        ->and(ColorConverterAction::run('rgb(10 20 30 / nope)', 'rgba'))->toBe('rgb(10, 20, 30)');
});

it('keeps OKLCH hue positive for blue hues', function (): void {
    expect(ColorConverterAction::run('#0000ff', 'oklch'))->toStartWith('oklch(');
});

it('caches repeated conversion (same result twice)', function (): void {
    $first = ColorConverterAction::run('#00ff00', 'rgb');
    $second = ColorConverterAction::run('#00ff00', 'rgb');
    expect($first)->toBe($second)->toBe('rgb(0, 255, 0)');
});

it('supports plain r,g,b format', function (): void {
    expect(ColorConverterAction::run('0,0,0', 'hex'))->toBe('#000000');
});

it('throws for invalid input or unsupported output format', function (string $input, string $exception, ?string $format = 'hex'): void {
    expect(fn () => ColorConverterAction::run($input, $format))->toThrow($exception);
})->with([
    ['rgb(256 0 0)', InvalidArgumentException::class],
    ['rgb(110% 0% 0%)', InvalidArgumentException::class],
    ['rgb(red 0 0)', InvalidArgumentException::class],
    ['rgb(126 126 / 15%)', InvalidArgumentException::class],
    ['#ffffff', InvalidArgumentException::class, 'cmyk'],
]);
