<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Spatie\LaravelData\Data;

final class ParentLanguageEligibilityData extends Data
{
    /** @param list<string> $missingLanguages */
    public function __construct(
        public readonly bool $accessible,
        public readonly array $missingLanguages = [],
        public readonly ?string $editUrl = null,
        public readonly bool $compatible = false,
    ) {}
}
