<?php

declare(strict_types=1);

use Capell\Admin\Support\Csv\CsvCell;

it('prefixes every spreadsheet formula trigger and leaves ordinary text alone', function (string $value): void {
    expect(CsvCell::neutralise($value))->toBe("'" . $value);
})->with(['=1+1', '+1', '-1', '@SUM(A1)', "\tcmd", "\rcmd", "'=1+1"]);

it('does not touch ordinary cells', function (?string $value, string $expected): void {
    expect(CsvCell::neutralise($value))->toBe($expected);
})->with([
    ['Home', 'Home'],
    ['/old-path', '/old-path'],
    ['', ''],
    [null, ''],
    ["it's fine", "it's fine"],
    ['a=b', 'a=b'],
]);

it('restores exactly what the export neutralised', function (string $value): void {
    expect(CsvCell::restore(CsvCell::neutralise($value)))->toBe($value);
})->with(['=1+1', '+1', '-1', '@SUM(A1)', "\tcmd", "'=1+1", "''-2", 'Home', "it's fine", "'plain"]);

it('restores a value only when it carries the export prefix', function (): void {
    expect(CsvCell::restore("'=1+1"))->toBe('=1+1')
        ->and(CsvCell::restore("'quoted"))->toBe("'quoted")
        ->and(CsvCell::restore(42))->toBe(42)
        ->and(CsvCell::restore(null))->toBeNull();
});
