<?php

declare(strict_types=1);

use Capell\Core\Actions\GetNameFromTranslationsAction;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Support\Collection;

dataset('translations_array', fn (): array => [
    // Matching language_id
    [[
        ['language_id' => 1, 'title' => 'English'],
        ['language_id' => 2, 'title' => 'French'],
    ], 2, 'French'],
    // No match, fallback to first
    [[
        ['language_id' => 1, 'title' => 'English'],
        ['language_id' => 2, 'title' => 'French'],
    ], 3, 'English'],
    // Empty collection
    [[], 1, null],
    // Null title in match, fallback to first with title
    [[
        ['language_id' => 1, 'title' => null],
        ['language_id' => 2, 'title' => 'French'],
    ], 1, null],
]);

dataset('translations_model', fn (): array => [
    // Matching language_id
    [
        fn (): array => [
            Translation::query()->make(['language_id' => 1, 'title' => 'English']),
            Translation::query()->make(['language_id' => 2, 'title' => 'French']),
        ],
        2,
        'French',
    ],
    // No match, fallback to first
    [
        fn (): array => [
            Translation::query()->make(['language_id' => 1, 'title' => 'English']),
            Translation::query()->make(['language_id' => 2, 'title' => 'French']),
        ],
        3,
        'English',
    ],
    // Empty collection
    [fn (): array => [], 1, null],
    // Null title in match, fallback to first with title
    [
        fn (): array => [
            Translation::query()->make(['language_id' => 1, 'title' => null]),
            Translation::query()->make(['language_id' => 2, 'title' => 'French']),
        ],
        1,
        null,
    ],
]);

it('returns correct title from array translations', function (array $translations, int $siteLanguageId, ?string $expected): void {
    $site = Site::query()->make(['language_id' => $siteLanguageId]);
    $collection = Collection::make($translations);

    $result = GetNameFromTranslationsAction::run($collection, $site);

    expect($result)->toBe($expected);
})->with('translations_array');

it('returns correct title from model translations', function (callable $translationsFactory, int $siteLanguageId, ?string $expected): void {
    $site = Site::query()->make(['language_id' => $siteLanguageId]);
    $collection = Collection::make($translationsFactory());

    $result = GetNameFromTranslationsAction::run($collection, $site);

    expect($result)->toBe($expected);
})->with('translations_model');

it('returns null for null translation input', function (): void {
    $site = Site::query()->make(['language_id' => 1]);
    $collection = Collection::make([null]);

    $result = GetNameFromTranslationsAction::run($collection, $site);

    expect($result)->toBeNull();
});
