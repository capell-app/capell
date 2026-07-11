<?php

declare(strict_types=1);

use Capell\Admin\Actions\CheckTranslationCompletenessAction;
use Capell\Core\Models\Language;
use Capell\Core\Models\Page;
use Capell\Core\Models\Translation;

/**
 * @param  array<string, array<int, string>|null>  $keys
 * @return array<string, array<int, string>|null>
 */
function translationCompletenessKeys(array $keys): array
{
    return $keys;
}

it('returns 100% for fully complete translation', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'title' => 'Hello',
        'content' => 'World',
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'title' => 'Bonjour',
        'content' => 'Monde',
    ]);
    $keys = ['title' => null, 'content' => null];
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBe(100);
});

it('returns 0% for completely blank translation', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'title' => 'Hello',
        'content' => 'World',
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'title' => '',
        'content' => '',
    ]);
    $keys = ['title' => null, 'content' => null];
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBe(0);
});

it('returns 50% for half complete translation', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'title' => 'Hello',
        'content' => 'World',
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'title' => 'Bonjour',
        'content' => '',
    ]);
    $keys = ['title' => null, 'content' => null];
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBe(50);
});

it('returns null if all default values are blank', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'title' => '',
        'content' => '',
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'title' => '',
        'content' => '',
    ]);
    $keys = ['title' => null, 'content' => null];
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBeNull();
});

it('handles nested JSON completeness', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'meta' => ['subtitle' => 'A', 'summary' => 'B'],
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'meta' => ['subtitle' => 'Un', 'summary' => ''],
    ]);
    $keys = translationCompletenessKeys(['meta' => ['subtitle', 'summary']]);
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBe(50);
});

it('returns null if no keys are provided', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'title' => 'Hello',
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'title' => 'Bonjour',
    ]);
    $keys = [];
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBeNull();
});

it('returns 100% if translated values match default but are not blank', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'title' => 'Hello',
        'content' => 'World',
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'title' => 'Hello',
        'content' => 'World',
    ]);
    $keys = ['title' => null, 'content' => null];
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBe(100);
});

it('ignores nonexistent keys', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'title' => 'Hello',
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'title' => 'Bonjour',
    ]);
    $keys = ['title' => null, 'nonexistent' => null];
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBe(100);
});

it('handles missing subkey in translation', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'meta' => ['subtitle' => 'A', 'summary' => 'B'],
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'meta' => ['subtitle' => 'Un'], // summary missing
    ]);
    $keys = translationCompletenessKeys(['meta' => ['subtitle', 'summary']]);
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBe(50);
});

it('handles null JSON field', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'meta' => ['subtitle' => 'A', 'summary' => 'B'],
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'meta' => null,
    ]);
    $keys = translationCompletenessKeys(['meta' => ['subtitle', 'summary']]);
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBe(0);
});

it('handles empty array JSON field', function (): void {
    $defaultLang = Language::factory()->createOne(['default' => true]);
    $frLang = Language::factory()->createOne(['default' => false, 'code' => 'fr']);
    $page = Page::factory()->createOne();
    $default = Translation::factory()->translatable($page)->for($defaultLang)->create([
        'meta' => ['subtitle' => 'A', 'summary' => 'B'],
    ]);
    $fr = Translation::factory()->translatable($page)->for($frLang)->create([
        'meta' => [],
    ]);
    $keys = translationCompletenessKeys(['meta' => ['subtitle', 'summary']]);
    $result = CheckTranslationCompletenessAction::run($fr, $keys);
    expect($result)->toBe(0);
});
