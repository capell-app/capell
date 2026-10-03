<?php

declare(strict_types=1);

// MariaDB's legacy timestamp semantics (10.5, or 10.11 with
// explicit_defaults_for_timestamp=0) assign invalid zero defaults to later
// required TIMESTAMP columns. New migrations must be explicit.
const FOUNDATION_TIMESTAMP_RULE_SINCE = '2026_09_29';

/** @return list<string> */
function foundationTimestampsWithoutDefault(string $source): array
{
    $offenders = [];
    foreach (explode(';', $source) as $statement) {
        if (preg_match('/->timestamp(?:Tz)?\(\s*[\'"]([^\'"]+)[\'"]/', $statement, $column) !== 1) {
            continue;
        }

        if (preg_match('/->(?:nullable\(\s*(?:true\s*)?\)|useCurrent\(|default\()/', $statement) !== 1) {
            $offenders[] = $column[1];
        }
    }

    return $offenders;
}

it('rejects required timestamps without explicit defaults in new foundation migrations', function (): void {
    $offenders = [];
    $paths = glob(dirname(__DIR__, 2) . '/packages/*/database/{migrations,settings}/*.php', GLOB_BRACE);
    expect($paths)->not->toBeFalse()->not->toBeEmpty();
    foreach ($paths === false ? [] : $paths as $path) {
        if (strcmp(basename($path), FOUNDATION_TIMESTAMP_RULE_SINCE) < 0) {
            continue;
        }

        foreach (foundationTimestampsWithoutDefault((string) file_get_contents($path)) as $column) {
            $offenders[] = basename($path) . ': ' . $column;
        }
    }

    expect($offenders)->toBe([], 'Use dateTime(), nullable(), useCurrent() or default() for required timestamp columns.');
});

it('recognises unsafe production timestamps and explicit alternatives', function (string $statement, array $offenders): void {
    expect(foundationTimestampsWithoutDefault($statement))->toBe($offenders);
})->with([
    ["\$table->timestamp('expires_at')->index();", ['expires_at']],
    ['\$table->timestampTz("expires_at")->index();', ['expires_at']],
    ["\$table->timestamp('at')->nullable(false);", ['at']],
    ["\$table->timestamp('at')->useCurrentOnUpdate();", ['at']],
    ["\$table->timestamp('at')->nullable();", []],
    ["\$table->timestamp('at')->nullable(true);", []],
    ["\$table->timestamp('at')\n    ->useCurrent();", []],
    ["\$table->timestamp('at')->default('2026-09-29 00:00:00');", []],
    ["\$table->dateTime('expires_at')->index();", []],
]);
