<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use BackedEnum;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Enums\PageOrderEnum;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class PageListingRequestData
{
    /**
     * @param  Pageable<Model>|null  $page
     * @param  class-string<Pageable<Model>>|non-empty-string|null  $morphModel
     * @param  Closure(Builder<Model>): void|null  $modifyQuery
     */
    public function __construct(
        public readonly Language $language,
        public readonly ?Site $site = null,
        public readonly ?Pageable $page = null,
        public readonly ?string $type = null,
        public readonly ?int $limit = null,
        public readonly ?int $paginationPage = null,
        public readonly ?PageOrderEnum $ordering = null,
        public readonly ?string $pageType = null,
        public readonly null|string|BackedEnum $pageGroup = null,
        public readonly ?string $typeKey = null,
        public readonly bool $optionalLanguage = false,
        public readonly bool $withChildrenCount = false,
        public readonly bool $withChildren = false,
        // Kept in the typed request for exact compatibility with getPages(); canonical hydration always loads image.
        public readonly bool $withImage = false,
        public readonly bool $withPagination = false,
        public readonly bool $withParent = false,
        public readonly bool $withDate = false,
        public readonly bool $onlyListableTypes = true,
        public readonly string $paginationKey = 'pages',
        public readonly string $cacheKeySuffix = '',
        public readonly ?string $morphModel = null,
        public readonly bool $useCache = true,
        public readonly ?Closure $modifyQuery = null,
    ) {}

    /**
     * Build the typed request from the deprecated PageLoader::getPages() contract.
     *
     * @param  Pageable<Model>|null  $page
     * @param  class-string<Pageable<Model>>|non-empty-string|null  $morphModel
     * @param  Closure(Builder<Model>): void|null  $modifyQuery
     */
    public static function fromLegacy(
        Language $language,
        ?Site $site = null,
        ?Pageable $page = null,
        ?string $type = null,
        ?int $limit = null,
        ?int $paginationPage = null,
        ?PageOrderEnum $ordering = null,
        ?string $pageType = null,
        null|string|BackedEnum $pageGroup = null,
        ?string $typeKey = null,
        bool $optionalLanguage = false,
        bool $withChildrenCount = false,
        bool $withChildren = false,
        bool $withImage = false,
        bool $withPagination = false,
        bool $withParent = false,
        bool $withDate = false,
        bool $onlyListableTypes = true,
        string $paginationKey = 'pages',
        string $cacheKeyPrepend = '',
        ?string $morphModel = null,
        bool $useCache = true,
        ?Closure $modifyQuery = null,
    ): self {
        return new self(
            language: $language,
            site: $site,
            page: $page,
            type: $type,
            limit: $limit,
            paginationPage: $paginationPage,
            ordering: $ordering,
            pageType: $pageType,
            pageGroup: $pageGroup,
            typeKey: $typeKey,
            optionalLanguage: $optionalLanguage,
            withChildrenCount: $withChildrenCount,
            withChildren: $withChildren,
            withImage: $withImage,
            withPagination: $withPagination,
            withParent: $withParent,
            withDate: $withDate,
            onlyListableTypes: $onlyListableTypes,
            paginationKey: $paginationKey,
            cacheKeySuffix: $cacheKeyPrepend,
            morphModel: $morphModel,
            useCache: $useCache,
            modifyQuery: $modifyQuery,
        );
    }

    public function canUseCache(): bool
    {
        return $this->useCache
            && ($this->modifyQuery === null || $this->cacheKeySuffix !== '');
    }
}
