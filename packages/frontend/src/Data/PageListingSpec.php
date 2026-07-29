<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use BackedEnum;
use Capell\Core\Enums\PageOrderEnum;

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

    public static function fromRequest(
        PageListingRequestData $request,
        ?int $effectiveLimit = null,
        ?PageOrderEnum $effectiveOrdering = null,
    ): self {
        return new self(
            languageId: $request->language->id,
            siteId: $request->site?->id,
            type: $request->type,
            ordering: $effectiveOrdering ?? $request->ordering,
            pageType: $request->pageType,
            pageGroup: $request->pageGroup instanceof BackedEnum
                ? (string) $request->pageGroup->value
                : $request->pageGroup,
            typeKey: $request->typeKey,
            morphModel: $request->morphModel,
            pageableId: $request->page?->getKey(),
            pageableType: $request->page?->getMorphClass(),
            optionalLanguage: $request->optionalLanguage,
            onlyListableTypes: $request->onlyListableTypes,
            limit: $effectiveLimit ?? $request->limit,
            cacheKeySuffix: $request->cacheKeySuffix,
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
