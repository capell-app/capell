<?php

declare(strict_types=1);

use Capell\Core\Actions\UpdateRgbColorAction;

it('updates rgb color fields', function (): void {
    $result = UpdateRgbColorAction::run(['r' => 100, 'g' => 150, 'b' => 200], ['r' => 50]);
    assert(is_array($result));

    expect($result['r'])->toBe(50)
        ->and($result['g'])->toBe(150)
        ->and($result['b'])->toBe(200);
});

it('clamps values to 0..255', function (): void {
    $result = UpdateRgbColorAction::run('-10 999 0');

    expect($result)->toBe('rgb(-10 999 0)');
});

it('returns null for empty or zero string', function (): void {
    expect(UpdateRgbColorAction::run(''))->toBeNull();
    expect(UpdateRgbColorAction::run('0'))->toBeNull();
});

it('returns rgb string for comma-separated string', function (): void {
    expect(UpdateRgbColorAction::run('10,20,30'))->toBe('rgb(10, 20, 30)');
});

it('returns rgba string for 4-component string', function (): void {
    expect(UpdateRgbColorAction::run('10,20,30,0.5'))->toBe('rgba(10, 20, 30, 0.5)');
});

it('returns input if already rgb(a) string', function (): void {
    expect(UpdateRgbColorAction::run('rgb(1, 2, 3)'))->toBe('rgb(1, 2, 3)');
    expect(UpdateRgbColorAction::run('rgba(1, 2, 3, 0.5)'))->toBe('rgba(1, 2, 3, 0.5)');
});

it('merges and clamps array input with override', function (): void {
    $result = UpdateRgbColorAction::run(['r' => 300, 'g' => -10, 'b' => 100], ['g' => 200]);
    expect($result)->toBe(['r' => 255, 'g' => 200, 'b' => 100]);
});

it('handles missing array keys', function (): void {
    $result = UpdateRgbColorAction::run(['r' => 10]);
    expect($result)->toBe(['r' => 10, 'g' => 0, 'b' => 0]);
});

it('returns null for invalid array input', function (): void {
    expect(UpdateRgbColorAction::run([], []))->toBe(['r' => 0, 'g' => 0, 'b' => 0]);
});

it('returns rgb for string with extra whitespace', function (): void {
    expect(UpdateRgbColorAction::run('  1 , 2 , 3  '))->toBe('rgb(1, 2, 3)');
});

it('extracts rgb and rgba values for storage-friendly metadata', function (): void {
    expect(UpdateRgbColorAction::run('rgb(10, 20, 30)', extract: true))->toBe('10,20,30')
        ->and(UpdateRgbColorAction::run('rgba(10, 20, 30, 0.35)', extract: true))->toBe('10,20,30,0.35')
        ->and(UpdateRgbColorAction::run('not-a-color', extract: true))->toBe('not-a-color');
});
