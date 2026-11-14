<?php

declare(strict_types=1);

namespace Capell\Core\Data\EditorImpact;

use Spatie\LaravelData\Data;

final class EditorImpactUrlData extends Data
{
    public function __construct(
        public readonly string $locale,
        public readonly string $url,
        public readonly ?string $languageName = null,
    ) {}

    /** The language as an editor knows it, falling back to the code when no name is known. */
    public function languageLabel(): string
    {
        return $this->languageName !== null && $this->languageName !== '' ? $this->languageName : $this->locale;
    }
}
