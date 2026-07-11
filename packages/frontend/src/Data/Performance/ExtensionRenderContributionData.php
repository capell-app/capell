<?php

declare(strict_types=1);

namespace Capell\Frontend\Data\Performance;

use Spatie\LaravelData\Data;

final class ExtensionRenderContributionData extends Data
{
    /**
     * @param  list<string>  $cacheTags
     * @param  list<string>  $variesBy
     */
    public function __construct(
        public readonly string $packageName,
        public readonly string $surface,
        public readonly string $contributionType,
        public readonly ?string $contributionClass,
        public readonly float $elapsedMilliseconds,
        public readonly int $frontendRenderBudgetMs,
        public readonly array $cacheTags,
        public readonly bool $cacheable,
        public readonly bool $sensitiveOutput,
        public readonly array $variesBy,
        public readonly bool $budgetExceeded,
    ) {}
}
