<?php

declare(strict_types=1);

use Capell\Core\Models\Language;

it('honours an explicit right-to-left choice stored in meta', function (): void {
    $language = new Language(['code' => 'en', 'meta' => ['rtl' => true]]);

    expect($language->direction())->toBe('rtl');
});

it('honours an explicit left-to-right choice stored in meta over the fallback list', function (): void {
    $language = new Language(['code' => 'ar', 'meta' => ['rtl' => false]]);

    expect($language->direction())->toBe('ltr');
});

it('falls back to the root subtag list when meta carries no choice', function (string $code): void {
    $language = new Language(['code' => $code, 'meta' => null]);

    expect($language->direction())->toBe('rtl');
})->with(['ar', 'he', 'fa', 'ur']);

it('matches a regional tag on its root subtag', function (): void {
    $language = new Language(['code' => 'ar-EG', 'meta' => null]);

    expect($language->direction())->toBe('rtl');
});

it('treats an underscore-separated regional tag as its root subtag', function (): void {
    $language = new Language(['code' => 'ar_EG', 'meta' => null]);

    expect($language->direction())->toBe('rtl');
});

it('returns left-to-right for a language outside the fallback list', function (): void {
    $language = new Language(['code' => 'fr', 'meta' => null]);

    expect($language->direction())->toBe('ltr');
});

it('falls back to the locale when the code is empty', function (): void {
    $language = new Language(['code' => '', 'locale' => 'he_IL', 'meta' => null]);

    expect($language->direction())->toBe('rtl');
});

it('resolves a direction for a bare tag without a model', function (): void {
    expect(Language::directionForCode('ur-PK'))->toBe('rtl')
        ->and(Language::directionForCode('en'))->toBe('ltr');
});

it('defaults an absent tag to left-to-right', function (): void {
    expect(Language::directionForCode(null))->toBe('ltr')
        ->and(Language::directionForCode(''))->toBe('ltr');
});
