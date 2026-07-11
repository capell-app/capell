<?php

declare(strict_types=1);

namespace Capell\Core\Data\Manifest;

use Override;
use Spatie\LaravelData\Data;

final class ExtensionPerformanceBudgetData extends Data
{
    /**
     * @param  list<string>  $cacheTags
     */
    public function __construct(
        public readonly int $frontendRenderBudgetMs,
        public readonly int $adminQueryBudget,
        public readonly array $cacheTags,
        public readonly ExtensionCacheSafetyData $cacheSafety,
        public readonly ?int $cssSizeBudgetBytes = null,
        public readonly ?int $jsSizeBudgetBytes = null,
        public readonly ?bool $requiresLivewire = null,
        public readonly ?string $cacheabilityProfile = null,
        public readonly ?bool $criticalCssEligible = null,
        public readonly ?bool $publicQueryRisk = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            frontendRenderBudgetMs: (int) ($data['frontendRenderBudgetMs'] ?? 0),
            adminQueryBudget: (int) ($data['adminQueryBudget'] ?? 0),
            cacheTags: self::stringList($data['cacheTags'] ?? []),
            cacheSafety: ExtensionCacheSafetyData::fromArray(is_array($data['cacheSafety'] ?? null) ? $data['cacheSafety'] : []),
            cssSizeBudgetBytes: self::nullablePositiveInt($data['cssSizeBudgetBytes'] ?? null),
            jsSizeBudgetBytes: self::nullablePositiveInt($data['jsSizeBudgetBytes'] ?? null),
            requiresLivewire: self::nullableBool($data['requiresLivewire'] ?? null),
            cacheabilityProfile: self::nullableString($data['cacheabilityProfile'] ?? null),
            criticalCssEligible: self::nullableBool($data['criticalCssEligible'] ?? null),
            publicQueryRisk: self::nullableBool($data['publicQueryRisk'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function toArray(): array
    {
        return [
            'frontendRenderBudgetMs' => $this->frontendRenderBudgetMs,
            'adminQueryBudget' => $this->adminQueryBudget,
            'cacheTags' => $this->cacheTags,
            'cacheSafety' => $this->cacheSafety->toArray(),
            'cssSizeBudgetBytes' => $this->cssSizeBudgetBytes,
            'jsSizeBudgetBytes' => $this->jsSizeBudgetBytes,
            'requiresLivewire' => $this->requiresLivewire,
            'cacheabilityProfile' => $this->cacheabilityProfile,
            'criticalCssEligible' => $this->criticalCssEligible,
            'publicQueryRisk' => $this->publicQueryRisk,
        ];
    }

    /** @return list<string> */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }

    private static function nullablePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private static function nullableBool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
