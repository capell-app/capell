<?php

declare(strict_types=1);

use Capell\Core\Actions\Translations\ResolveLanguageFallbackChainAction;
use Capell\Core\Actions\Translations\ResolveTranslationAction;
use Capell\Core\Data\TranslationResolutionData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Language;
use Capell\Core\Models\Media;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function translationFallbackPage(): Page
{
    return Page::factory()->createOne();
}

function translationFallbackFresh(Page $page): Page
{
    return Page::query()->whereKey($page->getKey())->firstOrFail();
}

function translationFallbackTitle(Page $page, Language $language, string $title): Translation
{
    return Translation::factory()
        ->translatable($page)
        ->language($language)
        ->createOne(['title' => $title]);
}

it('returns the requested language title whichever translation row was written first', function (bool $frenchFirst): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne();
    $page = translationFallbackPage();
    $page->translations()->delete();

    if ($frenchFirst) {
        translationFallbackTitle($page, $french, 'Bonjour');
        translationFallbackTitle($page, $english, 'Hello');
    } else {
        translationFallbackTitle($page, $english, 'Hello');
        translationFallbackTitle($page, $french, 'Bonjour');
    }

    $fresh = translationFallbackFresh($page);

    expect($fresh->translationFor($french)?->title)->toBe('Bonjour')
        ->and($fresh->translationFor($english->getKey())?->title)->toBe('Hello')
        ->and(ResolveTranslationAction::run($fresh, $french)?->translation->title)->toBe('Bonjour');
})->with([
    'french row first' => [true],
    'english row first' => [false],
]);

it('reads the language-scoped translation from loaded relations without querying', function (): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne();
    $page = translationFallbackPage();
    $page->translations()->delete();
    translationFallbackTitle($page, $french, 'Bonjour');
    translationFallbackTitle($page, $english, 'Hello');

    $loaded = Page::query()->with('translations')->whereKey($page->getKey())->firstOrFail();

    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $title = $loaded->translationFor($french)?->title;
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($title)->toBe('Bonjour')->and($queries)->toBe([]);
});

it('ignores a translation relation loaded for another language', function (Closure $languageOf): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne();
    $page = translationFallbackPage();
    $page->translations()->delete();
    translationFallbackTitle($page, $english, 'Hello');
    translationFallbackTitle($page, $french, 'Bonjour');

    $loaded = Page::query()
        ->with(['translation' => fn ($query) => $query->where('language_id', $languageOf($english, $french)->getKey())])
        ->whereKey($page->getKey())
        ->firstOrFail();

    expect($loaded->translationFor($french)?->title)->toBe('Bonjour')
        ->and($loaded->translationFor($english)?->title)->toBe('Hello');
})->with([
    'english loaded' => [fn (Language $english, Language $french): Language => $english],
    'french loaded' => [fn (Language $english, Language $french): Language => $french],
]);

it('serves the configured fallback translation and marks it as fallback content', function (): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->getKey()]);
    $page = translationFallbackPage();
    $page->translations()->delete();
    translationFallbackTitle($page, $english, 'Hello');

    $resolution = ResolveTranslationAction::run(translationFallbackFresh($page), $french);

    expect($resolution)->toBeInstanceOf(TranslationResolutionData::class)
        ->and($resolution?->translation->title)->toBe('Hello')
        ->and($resolution?->requestedLanguageId)->toBe($french->getKey())
        ->and($resolution?->servedLanguageId)->toBe($english->getKey())
        ->and($resolution?->isFallback())->toBeTrue()
        ->and(translationFallbackFresh($page)->translationFor($french))->toBeNull();
});

it('does not mark an exact-language translation as fallback content', function (): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->getKey()]);
    $page = translationFallbackPage();
    $page->translations()->delete();
    translationFallbackTitle($page, $english, 'Hello');
    translationFallbackTitle($page, $french, 'Bonjour');

    $resolution = ResolveTranslationAction::run(translationFallbackFresh($page), $french);

    expect($resolution?->translation->title)->toBe('Bonjour')
        ->and($resolution?->isFallback())->toBeFalse();
});

it('resolves nothing when the language has no translation and no fallback is configured', function (): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne();
    $page = translationFallbackPage();
    $page->translations()->delete();
    translationFallbackTitle($page, $english, 'Hello');

    expect(ResolveTranslationAction::run(translationFallbackFresh($page), $french))->toBeNull();
});

it('resolves nothing when fallback is not allowed by the caller', function (): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->getKey()]);
    $page = translationFallbackPage();
    $page->translations()->delete();
    translationFallbackTitle($page, $english, 'Hello');

    expect(ResolveTranslationAction::run(translationFallbackFresh($page), $french, followFallback: false))->toBeNull();
});

it('walks a multi-step fallback chain in configured order', function (): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $german = Language::factory()->german()->createOne(['fallback_language_id' => $english->getKey()]);
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $german->getKey()]);
    $page = translationFallbackPage();
    $page->translations()->delete();
    translationFallbackTitle($page, $english, 'Hello');
    translationFallbackTitle($page, $german, 'Hallo');

    $resolution = ResolveTranslationAction::run(translationFallbackFresh($page), $french);

    expect(ResolveLanguageFallbackChainAction::run($french))->toBe([$french->getKey(), $german->getKey(), $english->getKey()])
        ->and($resolution?->translation->title)->toBe('Hallo')
        ->and($resolution?->servedLanguageId)->toBe($german->getKey());
});

it('stops a cyclic fallback chain instead of looping', function (): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->getKey()]);
    // Saving a cycle is rejected; data written around the model must still not loop.
    DB::table($english->getTable())->where('id', $english->getKey())->update(['fallback_language_id' => $french->getKey()]);
    $page = translationFallbackPage();
    $page->translations()->delete();

    expect(ResolveLanguageFallbackChainAction::run($french))->toBe([$french->getKey(), $english->getKey()])
        ->and(ResolveTranslationAction::run(translationFallbackFresh($page), $french))->toBeNull();
});

it('bounds the fallback chain depth', function (): void {
    $previous = Language::factory()->createOne(['code' => 'l0']);

    foreach (range(1, ResolveLanguageFallbackChainAction::MAX_DEPTH + 3) as $index) {
        $previous = Language::factory()->createOne(['code' => 'l' . $index, 'fallback_language_id' => $previous->getKey()]);
    }

    expect(ResolveLanguageFallbackChainAction::run($previous))
        ->toHaveCount(ResolveLanguageFallbackChainAction::MAX_DEPTH + 1);
});

it('does not fall back to a disabled or deleted language', function (string $state): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->getKey()]);
    $page = translationFallbackPage();
    $page->translations()->delete();
    translationFallbackTitle($page, $english, 'Hello');

    if ($state === 'disabled') {
        $english->forceFill(['status' => false])->save();
    } else {
        $english->delete();
    }

    expect(ResolveLanguageFallbackChainAction::run($french))->toBe([$french->getKey()])
        ->and(ResolveTranslationAction::run(translationFallbackFresh($page), $french))->toBeNull();
})->with(['disabled', 'deleted']);

it('clears the fallback when the fallback language is removed from the database', function (): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->getKey()]);

    $english->forceDelete();

    expect($french->refresh()->fallback_language_id)->toBeNull()
        ->and($french->fallbackLanguage)->toBeNull();
});

it('refuses to save a language that falls back to itself or into a loop', function (): void {
    $english = Language::factory()->english(isDefault: false)->createOne();
    $french = Language::factory()->french()->createOne(['fallback_language_id' => $english->getKey()]);

    expect(fn () => $french->forceFill(['fallback_language_id' => $french->getKey()])->save())
        ->toThrow(ValidationException::class)
        ->and(fn () => $english->forceFill(['fallback_language_id' => $french->getKey()])->save())
        ->toThrow(ValidationException::class)
        ->and($english->refresh()->fallback_language_id)->toBeNull()
        ->and($french->refresh()->fallback_language_id)->toBe($english->getKey());
});

it('explains a rejected fallback in words, not translation keys', function (): void {
    $french = Language::factory()->french()->createOne();

    try {
        $french->forceFill(['fallback_language_id' => $french->getKey()])->save();
        $message = null;
    } catch (ValidationException $validationException) {
        $message = $validationException->errors()['fallback_language_id'][0] ?? null;
    }

    expect($message)->toBeString()->not->toBeEmpty()->not->toContain('::');
});

it('reads the title in the site default language when no translation was loaded, whatever the row order', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->createOne();
    $page = Page::factory()->site($site)->createOne();
    $page->translations()->delete();
    translationFallbackTitle($page, $french, 'Bonjour');
    translationFallbackTitle($page, $english, 'Hello');

    $fresh = translationFallbackFresh($page);

    expect($fresh->title)->toBe('Hello')
        ->and(translationFallbackFresh($page)->getTranslation('title'))->toBe('Hello')
        ->and($fresh->relationLoaded('translation'))->toBeFalse();
});

it('keeps the title of a translation loaded for the language in context', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->createOne();
    $page = Page::factory()->site($site)->createOne();
    $page->translations()->delete();
    translationFallbackTitle($page, $english, 'Hello');
    translationFallbackTitle($page, $french, 'Bonjour');

    $loaded = Page::query()
        ->with(['translation' => fn ($query) => $query->where('language_id', $french->getKey())])
        ->whereKey($page->getKey())
        ->firstOrFail();

    expect($loaded->title)->toBe('Bonjour');
});

it('reads the title of the first language in order when the site default language has no translation', function (): void {
    $english = Language::factory()->english()->createOne();
    $german = Language::factory()->german()->createOne();
    $french = Language::factory()->french()->createOne();
    $site = Site::factory()->language($english)->createOne();
    $page = Page::factory()->site($site)->createOne();
    $page->translations()->delete();
    translationFallbackTitle($page, $german, 'Hallo');
    translationFallbackTitle($page, $french, 'Bonjour');

    expect(translationFallbackFresh($page)->title)->toBe('Bonjour');
});

it('reads a model title again without querying once it has been resolved', function (): void {
    $english = Language::factory()->english()->createOne();
    $site = Site::factory()->language($english)->createOne();
    $page = Page::factory()->site($site)->createOne();
    $page->translations()->forceDelete();
    translationFallbackTitle($page, $english, 'Hello');
    $fresh = translationFallbackFresh($page);
    $first = $fresh->title;

    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $second = $fresh->title;
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($first)->toBe('Hello')->and($second)->toBe('Hello')->and($queries)->toBe([]);
});

it('orders the translations of a model without a site from cached language order', function (): void {
    $german = Language::factory()->german()->createOne(['order' => 2]);
    $french = Language::factory()->french()->createOne(['order' => 1]);
    $media = Media::factory()->model(translationFallbackPage())->createOne();
    Translation::factory()->translatable($media)->language($german)->createOne(['title' => 'Hallo']);
    Translation::factory()->translatable($media)->language($french)->createOne(['title' => 'Bonjour']);
    $fresh = static fn (): Media => Media::query()->with('translations')->whereKey($media->getKey())->firstOrFail();
    $warm = $fresh()->title;
    $second = $fresh();

    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        $title = $second->title;
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    expect($warm)->toBe('Bonjour')->and($title)->toBe('Bonjour')->and($queries)->toBe([]);

    // Reordering languages flushes the cached order through LanguageObserver.
    $german->update(['order' => 0]);

    expect($fresh()->title)->toBe('Hallo');
});

it('ignores a language map cached in id order by an earlier release', function (): void {
    $german = Language::factory()->german()->createOne(['order' => 2]);
    $french = Language::factory()->french()->createOne(['order' => 1]);
    // An earlier release cached the map in id order under the unversioned key.
    CapellCore::rememberCache('language-fallback-map', static fn (): array => [
        (int) $german->getKey() => ['fallback' => null, 'enabled' => true],
        (int) $french->getKey() => ['fallback' => null, 'enabled' => true],
    ]);

    expect(array_keys(ResolveLanguageFallbackChainAction::make()->languageOrder()))
        ->toBe([(int) $french->getKey(), (int) $german->getKey()]);
});

it('drops a language map cached by another request while the language change was uncommitted', function (): void {
    $english = Language::factory()->english()->createOne();
    $french = Language::factory()->french()->createOne();

    DB::transaction(function () use ($english, $french): void {
        $french->update(['fallback_language_id' => $english->getKey()]);
        // Another request reads the committed (old) state and caches it before this commits.
        CapellCore::rememberCache(ResolveLanguageFallbackChainAction::CACHE_KEY, static fn (): array => [
            (int) $english->getKey() => ['fallback' => null, 'enabled' => true],
            (int) $french->getKey() => ['fallback' => null, 'enabled' => true],
        ]);
    });

    expect(ResolveLanguageFallbackChainAction::run((int) $french->getKey()))->toBe([(int) $french->getKey(), (int) $english->getKey()]);
});
