<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Models\Translation;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;

/**
 * The translation chosen for a requested language, and whether it came from a
 * fallback language. Render data that serves fallback content must say so, so
 * hreflang alternates and the document language can exclude or label it.
 */
final class TranslationResolutionData extends Data
{
    public function __construct(
        #[Hidden]
        public readonly Translation $translation,
        public readonly int $requestedLanguageId,
        public readonly int $servedLanguageId,
    ) {}

    public function isFallback(): bool
    {
        return $this->servedLanguageId !== $this->requestedLanguageId;
    }
}
