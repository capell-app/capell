<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Fragments;

use Spatie\LaravelData\Data;

final class PublicFragmentReferenceData extends Data
{
    /**
     * @param  array<string, int|string>  $ownerContext
     */
    public function __construct(
        public readonly string $owner,
        public readonly int $formatVersion,
        public readonly string $pageableType,
        public readonly int|string $pageableId,
        public readonly int|string $siteId,
        public readonly int|string $languageId,
        public readonly string $contentVersion,
        public readonly array $ownerContext,
    ) {}
}
