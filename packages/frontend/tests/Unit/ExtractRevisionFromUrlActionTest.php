<?php

declare(strict_types=1);

use Capell\Frontend\Actions\ExtractRevisionFromUrlAction;

it('extracts revision from url or returns null', function (string $input, ?int $expected): void {
    expect(ExtractRevisionFromUrlAction::run($input))->toBe($expected);
})->with([
    // Path suffix extraction
    'suffix: valid revision' => ['/foo/bar{123}', 123],
    'suffix: no revision' => ['/foo/bar', null],
    'suffix: non-numeric' => ['/foo/bar{abc}', null],
    'suffix: zero' => ['/foo/bar{0}', 0],
    'suffix: negative not supported' => ['/foo/bar{-1}', null],
    'suffix: leading zeros' => ['/foo/bar{00123}', 123],
    'suffix: not at end' => ['/foo/bar{123}/baz', null],
    'suffix: with query' => ['/foo/bar{123}?foo=bar', 123],
    // Query string extraction
    'query: valid revision' => ['/foo/bar?revision=456', 456],
    'query: non-numeric' => ['/foo/bar?revision=abc', null],
    'query: empty' => ['/foo/bar?revision=', null],
    'query: with extra param' => ['/foo/bar?revision=789&foo=bar', 789],
    'query: revision after param' => ['/foo/bar?foo=bar&revision=321', 321],
    'query: no revision param' => ['/foo/bar?foo=bar', null],
    'query: multiple revision params' => ['/foo/bar?revision=999&revision=1000', 1000],
    // Both present: query string takes precedence
    'both: query takes precedence' => ['/foo/bar{123}?revision=456', 456],
    'both: query with extra param' => ['/foo/bar{123}?foo=bar&revision=789', 789],
    // Malformed/edge
    'empty string' => ['', null],
    'just braces' => ['{123}', 123],
    'no leading slash' => ['foo{123}', 123],
    'open brace only' => ['foo{', null],
    'close brace only' => ['foo}', null],
]);
