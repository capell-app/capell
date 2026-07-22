<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Spatie\LaravelData\Data;

final class CacheInvalidationRule extends Data
{
    public const string KIND_FORGET_KEY = 'forget-key';

    public const string KIND_FLUSH_FRONTEND_TAG = 'flush-frontend-tag';

    public const string KIND_INVALIDATE_PATTERN = 'invalidate-pattern';

    public const string KIND_PAGE_MODEL = 'page-model';

    public const string KIND_PAGE_LISTING = 'page-listing';

    public const string KIND_PUBLIC_RENDER_DATA = 'public-render-data';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $cacheKey = null,
        public readonly ?string $modelType = null,
        public readonly ?int $modelId = null,
        public readonly ?int $siteId = null,
        public readonly ?int $languageId = null,
    ) {}

    public static function forgetKey(string $cacheKey): self
    {
        return new self(kind: self::KIND_FORGET_KEY, cacheKey: $cacheKey);
    }

    public static function flushFrontendTag(): self
    {
        return new self(kind: self::KIND_FLUSH_FRONTEND_TAG);
    }

    public static function invalidatePattern(string $cachePattern): self
    {
        return new self(kind: self::KIND_INVALIDATE_PATTERN, cacheKey: $cachePattern);
    }

    public static function pageModel(string $modelType, int $modelId, int $siteId, int $languageId): self
    {
        return new self(
            kind: self::KIND_PAGE_MODEL,
            modelType: $modelType,
            modelId: $modelId,
            siteId: $siteId,
            languageId: $languageId,
        );
    }

    public static function pageListing(int $siteId, int $languageId): self
    {
        return new self(
            kind: self::KIND_PAGE_LISTING,
            siteId: $siteId,
            languageId: $languageId,
        );
    }

    public static function publicRenderData(string $modelType, int $modelId, int $siteId, int $languageId): self
    {
        return new self(
            kind: self::KIND_PUBLIC_RENDER_DATA,
            modelType: $modelType,
            modelId: $modelId,
            siteId: $siteId,
            languageId: $languageId,
        );
    }
}
