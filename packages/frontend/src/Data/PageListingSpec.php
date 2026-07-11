<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use BackedEnum;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;

final class PageListingSpec
{
    public function __construct(
        public readonly int $languageId,
        public readonly ?int $siteId,
        public readonly ?string $type,
        public readonly ?PageOrderEnum $ordering,
        public readonly ?string $pageType,
        public readonly ?string $pageGroup,
        public readonly ?string $typeKey,
        public readonly ?string $morphModel,
        public readonly ?int $pageableId,
        public readonly ?string $pageableType,
        public readonly bool $optionalLanguage,
        public readonly bool $onlyListableTypes,
        public readonly ?int $limit,
        public readonly string $cacheKeySuffix,
    ) {}

    public static function fromGetPages(
        Language $language,
        ?Site $site,
        ?Pageable $page,
        ?string $type,
        ?int $limit,
        ?PageOrderEnum $ordering,
        ?string $pageType,
        null|string|BackedEnum $pageGroup,
        ?string $typeKey,
        bool $optionalLanguage,
        bool $onlyListableTypes,
        ?string $morphModel,
        string $cacheKeySuffix,
    ): self {
        return new self(
            languageId: $language->id,
            siteId: $site?->id,
            type: $type,
            ordering: $ordering,
            pageType: $pageType,
            pageGroup: $pageGroup instanceof BackedEnum ? $pageGroup->value : $pageGroup,
            typeKey: $typeKey,
            morphModel: $morphModel,
            pageableId: $page?->getKey(),
            pageableType: $page?->getMorphClass(),
            optionalLanguage: $optionalLanguage,
            onlyListableTypes: $onlyListableTypes,
            limit: $limit,
            cacheKeySuffix: $cacheKeySuffix,
        );
    }

    public function toCacheKey(): string
    {
        $parts = ['page-ids', $this->languageId, $this->siteId ?? 'all'];

        if ($this->limit !== null) {
            $parts[] = 'limit-' . $this->limit;
        }

        if ($this->pageableId !== null && $this->pageableType !== null) {
            $parts[] = 'parent-' . $this->pageableType . '-' . $this->pageableId;
        }

        if ($this->type !== null) {
            $parts[] = 'type-' . $this->type;
        }

        if ($this->pageType !== null) {
            $parts[] = 'page-type-' . $this->pageType;
        }

        if ($this->pageGroup !== null) {
            $parts[] = 'group-' . $this->pageGroup;
        }

        if ($this->typeKey !== null) {
            $parts[] = 'type-key-' . $this->typeKey;
        }

        if ($this->ordering instanceof PageOrderEnum) {
            $parts[] = 'ordering-' . $this->ordering->value;
        }

        if ($this->morphModel !== null) {
            $parts[] = 'morph-' . $this->morphModel;
        }

        if ($this->optionalLanguage) {
            $parts[] = 'optional-lang';
        }

        if (! $this->onlyListableTypes) {
            $parts[] = 'all-types';
        }

        if ($this->cacheKeySuffix !== '') {
            $parts[] = $this->cacheKeySuffix;
        }

        return implode('-', $parts);
    }
}
