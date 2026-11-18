<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Capell\Frontend\Enums\VisitorLanguageDetection;
use Spatie\LaravelData\Data;

final class PublicLanguageSuggestionData extends Data implements PublicRenderData
{
    /** @param list<array{tag: string, name: string, direction: string}> $languages */
    public function __construct(
        public readonly VisitorLanguageDetection $mode,
        public readonly string $pageTag,
        public readonly string $pageLanguageName,
        public readonly array $languages,
    ) {}
}
