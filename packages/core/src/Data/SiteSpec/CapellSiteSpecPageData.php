<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class CapellSiteSpecPageData extends Data
{
    /**
     * @param  array<int, CapellSiteSpecSectionData>  $sections
     * @param  array<string, mixed>  $visibility
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $title,
        public readonly string $pageType,
        public readonly ?string $url = null,
        public readonly ?string $description = null,
        public readonly int $order = 0,
        public readonly string $contentStructure = 'html',
        #[DataCollectionOf(CapellSiteSpecSectionData::class)]
        public readonly array $sections = [],
        public readonly array $visibility = [],
        public readonly array $meta = [],
    ) {}

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return ['visibility' => ['sometimes', 'array'], 'meta' => ['sometimes', 'array']];
    }

    public function resolvedUrl(): string
    {
        return $this->url !== null && $this->url !== '' ? $this->url : '/' . ltrim($this->slug, '/');
    }
}
