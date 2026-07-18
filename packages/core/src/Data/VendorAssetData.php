<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use Capell\Core\Enums\VendorAssetEnum;
use Spatie\LaravelData\Data;

final class VendorAssetData extends Data
{
    public function __construct(
        public VendorAssetEnum $type,
        public string $value,
        public ?string $secondaryValue = null,
        public ?string $packageName = null,
        public ?string $condition = null,
    ) {}

    public static function tailwindImport(string $import, ?string $packageName = null): self
    {
        return new self(type: VendorAssetEnum::TailwindImport, value: $import, packageName: $packageName);
    }

    public static function tailwindPlugin(string $plugin, ?string $packageName = null): self
    {
        return new self(type: VendorAssetEnum::TailwindPlugin, value: $plugin, packageName: $packageName);
    }

    public static function tailwindSource(string $source, ?string $packageName = null): self
    {
        return new self(type: VendorAssetEnum::TailwindSource, value: $source, packageName: $packageName);
    }

    public static function tailwindThemeColor(string $colorName, string $colorValue, ?string $packageName = null): self
    {
        return new self(
            type: VendorAssetEnum::TailwindThemeColor,
            value: $colorName,
            secondaryValue: $colorValue,
            packageName: $packageName,
        );
    }

    public function path(): string
    {
        return $this->value;
    }

    public function file(): ?string
    {
        return $this->secondaryValue;
    }

    public function condition(): ?string
    {
        return $this->condition;
    }

    public function colorName(): string
    {
        return $this->value;
    }

    public function colorValue(): ?string
    {
        return $this->secondaryValue;
    }
}
