<?php

declare(strict_types=1);

namespace Capell\Core\Data\Theme;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
class AssetsData extends Data
{
    /**
     * @param  array<int, AssetEntryData>  $assets
     */
    public function __construct(
        public ?string $assetsBuildPath = null,
        public ?string $criticalAsset = null,
        public array $assets = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLegacyMeta(): array
    {
        return array_filter([
            'assets_build_path' => $this->assetsBuildPath,
            'critical_asset' => $this->criticalAsset,
            'assets' => array_map(fn (AssetEntryData $asset): array => $asset->toArray(), $this->assets),
        ], static fn (string|array|null $value): bool => $value !== null && $value !== []);
    }
}
