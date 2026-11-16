<?php

declare(strict_types=1);

use Capell\Core\Support\Install\Cli\DisplayWidthWrapper;

it('wraps words onto lines no wider than the column', function (): void {
    $lines = DisplayWidthWrapper::wrap('one two three four five', 10);

    expect($lines)->toBe(['one two', 'three four', 'five']);
});

it('splits a single word that is wider than the column', function (): void {
    $lines = DisplayWidthWrapper::wrap('/a/very/long/path/to/database.sqlite', 10);

    expect(implode('', $lines))->toBe('/a/very/long/path/to/database.sqlite')
        ->and(array_filter($lines, fn (string $line): bool => mb_strwidth($line) > 10))->toBeEmpty();
});

it('measures display width rather than bytes', function (): void {
    $accented = DisplayWidthWrapper::wrap('café café café', 9);
    $wide = DisplayWidthWrapper::wrap('日本語日本語', 6);

    expect($accented)->toBe(['café café', 'café'])
        ->and($wide)->toBe(['日本語', '日本語']);
});

it('keeps an empty line', function (): void {
    expect(DisplayWidthWrapper::wrap('', 10))->toBe(['']);
});
