<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Spatie\LaravelData\Data;

final class PageSiteTranslationsData extends Data
{
    /**
     * @param  array<int|string, mixed>  $translations
     * @param  list<int>  $unsupportedLanguageIds
     * @param  list<string>  $unsupportedLanguageNames
     */
    public function __construct(
        public readonly array $translations,
        public readonly array $unsupportedLanguageIds,
        public readonly array $unsupportedLanguageNames,
    ) {}
}
