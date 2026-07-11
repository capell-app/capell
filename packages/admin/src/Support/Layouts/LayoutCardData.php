<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Layouts;

use Capell\Core\Models\Layout;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelData\Data;

final class LayoutCardData extends Data
{
    /**
     * @param  array<int, string>  $containerNames
     */
    public function __construct(
        public string $title,
        public string $key,
        public ?string $imageUrl,
        public bool $isDefault,
        public bool $isEnabled,
        public ?string $siteName,
        public ?string $themeName,
        public int $containerCount,
        public string $lastUpdated,
        public array $containerNames,
    ) {}

    public static function fromLayout(Layout $layout): self
    {
        $containerNames = self::containerNames($layout);

        return new self(
            title: $layout->name,
            key: $layout->key,
            imageUrl: self::imageUrl($layout),
            isDefault: $layout->default,
            isEnabled: $layout->status,
            siteName: $layout->site?->name,
            themeName: $layout->theme?->name,
            containerCount: count($containerNames),
            lastUpdated: $layout->updated_at?->diffForHumans() ?? (string) __('capell-admin::table.never_updated'),
            containerNames: $containerNames,
        );
    }

    private static function imageUrl(Layout $layout): ?string
    {
        $url = $layout->getFirstMediaUrl('image');

        if ($url !== '') {
            return $url;
        }

        $admin = is_array($layout->admin) ? $layout->admin : [];
        $manualImage = $admin['image'] ?? null;

        if (is_string($manualImage) && $manualImage !== '') {
            return Storage::disk('public')->url($manualImage);
        }

        $generatedImage = $admin['generated_preview_image'] ?? null;

        return is_string($generatedImage) && $generatedImage !== ''
            ? Storage::disk('public')->url($generatedImage)
            : null;
    }

    /**
     * @return array<int, string>
     */
    private static function containerNames(Layout $layout): array
    {
        if (! is_array($layout->containers)) {
            return [];
        }

        return collect($layout->containers)
            ->map(fn (mixed $container, int|string $key): string => self::containerName($container, $key))
            ->filter(fn (string $name): bool => $name !== '')
            ->values()
            ->all();
    }

    private static function containerName(mixed $container, int|string $key): string
    {
        $name = is_array($container)
            ? ($container['name'] ?? $container['label'] ?? $container['key'] ?? null)
            : null;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return is_string($key) ? $key : '';
    }
}
