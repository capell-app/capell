<?php

declare(strict_types=1);

namespace Capell\Core\Support\Cache;

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Enums\ExtensionContributionReceiptType;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;

/**
 * The cache keys and wildcard patterns a package's public output depends on, per
 * model class. A package declares them here while booting, from any provider
 * bucket, without depending on Frontend; Frontend's cache invalidation reads
 * these declarations when the model changes. A pattern containing `*` is retired
 * by generation, any other entry is forgotten as an exact key.
 *
 * @experimental
 *
 * @extends AbstractKeyedRegistry<list<string>, string>
 */
final class CacheDependencyRegistry extends AbstractKeyedRegistry
{
    public function __construct(
        private readonly CapellCacheManager $cache,
    ) {}

    /**
     * @param  class-string|string  $modelClass
     * @param  string|array<string>  $cachePatterns
     */
    public function declare(string $modelClass, string|array $cachePatterns): void
    {
        $patterns = array_values(array_filter(
            is_array($cachePatterns) ? $cachePatterns : [$cachePatterns],
            static fn (mixed $pattern): bool => is_string($pattern) && $pattern !== '',
        ));

        $this->setItem($modelClass, array_values(array_unique([
            ...$this->patternsFor($modelClass),
            ...$patterns,
        ])));

        // Generation-based pattern retirement only covers keys whose pattern the
        // cache manager knows before they are written, so register it now.
        foreach ($patterns as $pattern) {
            $this->cache->registerCacheInvalidationPattern($pattern);
        }

        $this->receipts()?->recordContribution(
            ExtensionContributionReceiptType::CacheDependency,
            'cache-dependency:' . $modelClass,
            $modelClass,
            self::class,
            'frontend',
        );
    }

    /** @return list<string> */
    public function patternsFor(string $modelClass): array
    {
        return $this->getItem($modelClass) ?? [];
    }

    /** @return array<string, list<string>> */
    public function all(): array
    {
        return $this->allItems();
    }

    private function receipts(): ?RecordsExtensionContributionReceipt
    {
        return app()->bound(RecordsExtensionContributionReceipt::class)
            ? resolve(RecordsExtensionContributionReceipt::class)
            : null;
    }
}
