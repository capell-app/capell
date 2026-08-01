<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Layouts;

use Capell\Admin\Actions\Layouts\ResolveLayoutUsageAction;
use Capell\Admin\Data\RecordRelationshipCountData;
use Capell\Admin\Data\RecordStateData;
use Capell\Core\Models\Layout;
use Filament\Support\Icons\Heroicon;
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
        public int $pagesCount,
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
            pagesCount: is_numeric($layout->getAttributes()['pages_count'] ?? null)
                ? (int) $layout->getAttributes()['pages_count']
                : ResolveLayoutUsageAction::run($layout),
            siteName: $layout->site?->name,
            themeName: $layout->theme?->name,
            containerCount: count($containerNames),
            lastUpdated: $layout->updated_at?->diffForHumans() ?? (string) __('capell-admin::table.never_updated'),
            containerNames: $containerNames,
        );
    }

    /** @return list<RecordStateData> */
    public function states(): array
    {
        return array_values(array_filter([
            ! $this->isEnabled ? new RecordStateData(
                key: 'disabled',
                label: (string) __('capell-admin::form.disabled'),
                description: (string) __('capell-admin::table.status'),
                color: 'danger',
                icon: Heroicon::OutlinedEyeSlash,
                priority: 10,
            ) : null,
            $this->pagesCount === 0 ? new RecordStateData(
                key: 'unused',
                label: (string) __('capell-admin::table.layout_usage_unused'),
                description: (string) __('capell-admin::table.layout_usage_unused_tooltip'),
                color: 'warning',
                icon: Heroicon::OutlinedExclamationTriangle,
                priority: 20,
            ) : null,
        ]));
    }

    /** @return list<RecordRelationshipCountData> */
    public function relationships(): array
    {
        return [
            new RecordRelationshipCountData(
                key: 'pages',
                label: (string) __('capell-admin::table.total_pages'),
                count: $this->pagesCount,
            ),
        ];
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
