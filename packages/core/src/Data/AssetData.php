<?php

declare(strict_types=1);

namespace Capell\Core\Data;

use BackedEnum;
use Capell\Core\Models\AssetAttachment;
use Closure;
use Spatie\LaravelData\Data;

final class AssetData extends Data
{
    public function __construct(
        public string $name,
        /* @var class-string<Model> $model */
        public string $model,
        public null|string|Closure $label = null,
        public null|string|BackedEnum|Closure $icon = null,
        public null|string|BackedEnum|Closure $activeIcon = null,
        public bool $hasTranslations = false,
        /** @var array<string, mixed> */
        public array $data = [],
    ) {}

    public function getKey(): string
    {
        return mb_strtolower($this->name);
    }

    public function getLabel(): string
    {
        if (is_callable($this->label)) {
            return ($this->label)();
        }

        return $this->label ?? ucfirst($this->name);
    }

    public function getIcon(): null|string|BackedEnum
    {
        if (is_callable($this->icon)) {
            return ($this->icon)();
        }

        return $this->icon;
    }

    public function getActiveIcon(): null|string|BackedEnum
    {
        if ($this->activeIcon === null) {
            return $this->getIcon();
        }

        if (is_callable($this->activeIcon)) {
            return ($this->activeIcon)();
        }

        return $this->activeIcon;
    }

    public function getTitleKey(): string
    {
        return 'name';
    }

    /**
     * Return the number of asset relations that reference this asset type.
     * This indicates how many times a media / asset of this type is actively used.
     */
    public function usages(): int
    {
        return AssetAttachment::query()
            ->where('asset_type', $this->model)
            ->count();
    }
}
