<?php

declare(strict_types=1);

use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\ImageSourceType;
use Illuminate\Support\Facades\Lang;

describe('HasEnumOptions', function (): void {
    it('returns all options as value => label pairs', function (): void {
        expect(BlueprintSubjectEnum::options())->toBe([
            'page' => 'Page',
            'site' => 'Site',
            'theme' => 'Theme',
        ]);
    });

    it('includes every enum case as a key', function (): void {
        $expectedValues = array_map(fn (BlueprintSubjectEnum $case): string => $case->value, BlueprintSubjectEnum::cases());

        expect(array_keys(BlueprintSubjectEnum::options()))->toBe($expectedValues);
    });

    it('returns non-empty options', function (): void {
        expect(BlueprintSubjectEnum::options())->not->toBeEmpty();
    });

    it('returns the same array on repeated calls via static cache', function (): void {
        $first = BlueprintSubjectEnum::options();
        $second = BlueprintSubjectEnum::options();

        expect($first)->toBe($second);
    });

    it('recomputes translated labels when the locale changes', function (): void {
        // Uses throwaway locales because the memo is a method static that
        // survives for the life of the process, including across tests.
        Lang::addLines(['media.image_source.url' => 'From a URL'], 'enum-options-first', 'capell');
        Lang::addLines(['media.image_source.url' => 'Depuis une URL'], 'enum-options-second', 'capell');

        $originalLocale = app()->getLocale();

        try {
            app()->setLocale('enum-options-first');
            $firstLocaleOptions = ImageSourceType::options();

            app()->setLocale('enum-options-second');
            $secondLocaleOptions = ImageSourceType::options();
        } finally {
            app()->setLocale($originalLocale);
        }

        expect($firstLocaleOptions[ImageSourceType::Url->value])->toBe('From a URL')
            ->and($secondLocaleOptions[ImageSourceType::Url->value])->toBe('Depuis une URL');
    });
});
