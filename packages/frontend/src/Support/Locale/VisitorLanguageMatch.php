<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Locale;

/**
 * A resolved per-page sibling URL in the language the visitor asked for.
 */
final readonly class VisitorLanguageMatch
{
    public function __construct(
        /** Normalised BCP 47 tag of the page the visitor landed on. */
        public string $currentTag,
        /** Normalised BCP 47 tag of the sibling page. */
        public string $targetTag,
        /** Absolute URL of the sibling page, query string not yet applied. */
        public string $targetUrl,
    ) {}
}
