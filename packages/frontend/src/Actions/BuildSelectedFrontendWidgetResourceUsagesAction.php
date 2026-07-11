<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Enums\PresentationLoadingStrategy;
use Capell\Core\Models\Page;
use Capell\Frontend\Data\FrontendRenderContextData;
use Illuminate\Support\Arr;
use Lorisleiva\Actions\Concerns\AsObject;

class BuildSelectedFrontendWidgetResourceUsagesAction
{
    use AsObject;

    /**
     * @return array<int, array{widgetKey: string|null, resourceGroup: string, publicId: string, loadingStrategy: string|null}>
     */
    public function handle(FrontendRenderContextData $context): array
    {
        $blocks = [
            ...$this->pageBlocks($context),
            ...$this->layoutBlocks($context),
        ];

        return collect($blocks)
            ->flatMap(fn (array $block, int $index): array => $this->usagesForBlock($block, $index))
            ->unique(fn (array $usage): string => implode(':', [
                $usage['widgetKey'] ?? '',
                $usage['resourceGroup'],
                $usage['publicId'],
                $usage['loadingStrategy'] ?? '',
            ]))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pageBlocks(FrontendRenderContextData $context): array
    {
        $page = $context->page;

        if (! $page instanceof Page) {
            return [];
        }

        $page->loadMissing(['blueprint', 'translation']);

        $content = $page->translation?->content;

        return is_array($content) ? $this->flattenBlocks($content, 'page-content') : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function layoutBlocks(FrontendRenderContextData $context): array
    {
        $containers = $context->layout?->containers;

        return is_array($containers) ? $this->flattenBlocks($containers, 'layout') : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flattenBlocks(array $items, string $path): array
    {
        $blocks = [];

        foreach ($items as $key => $item) {
            if (! is_array($item)) {
                continue;
            }

            $itemPath = $path . '.' . $key;

            if ($this->resourceSettings($item) !== []) {
                $item['__capell_resource_path'] = $itemPath;
                $blocks[] = $item;
            }

            foreach (['data', 'widgets', 'elements', 'blocks', 'children', 'items'] as $childKey) {
                $children = $item[$childKey] ?? null;

                if (is_array($children)) {
                    if ($childKey === 'data') {
                        unset($children['__capell']);
                    }

                    $blocks = [
                        ...$blocks,
                        ...$this->flattenBlocks($children, $itemPath . '.' . $childKey),
                    ];
                }
            }
        }

        return $blocks;
    }

    /**
     * @return array<int, array{widgetKey: string|null, resourceGroup: string, publicId: string, loadingStrategy: string|null}>
     */
    private function usagesForBlock(array $block, int $index): array
    {
        $settings = $this->resourceSettings($block);
        $groups = $settings['groups'] ?? [];

        if (! is_array($groups)) {
            return [];
        }

        $widgetKey = $this->widgetKey($block);
        $publicId = $this->publicId($block, $index);
        $overrides = $this->loadingOverrides($settings);

        return collect($groups)
            ->filter(fn (mixed $group): bool => is_string($group) && $group !== '')
            ->map(fn (string $group): array => [
                'widgetKey' => $widgetKey,
                'resourceGroup' => $group,
                'publicId' => $publicId,
                'loadingStrategy' => $overrides[$group] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceSettings(array $block): array
    {
        $settings = Arr::get($block, 'data.__capell.resources')
            ?? Arr::get($block, 'meta.resources')
            ?? Arr::get($block, '__capell.resources')
            ?? Arr::get($block, 'resources');

        return is_array($settings) ? $settings : [];
    }

    /**
     * @return array<string, string>
     */
    private function loadingOverrides(array $settings): array
    {
        $overrides = $settings['loading_overrides'] ?? [];

        if (! is_array($overrides)) {
            return [];
        }

        return collect($overrides)
            ->filter(fn (mixed $override): bool => is_array($override))
            ->mapWithKeys(function (array $override): array {
                $group = $override['group'] ?? null;
                $strategy = $override['loading_strategy'] ?? null;

                if (! is_string($group) || ! is_string($strategy) || ! PresentationLoadingStrategy::tryFrom($strategy) instanceof PresentationLoadingStrategy) {
                    return [];
                }

                return [$group => $strategy];
            })
            ->all();
    }

    private function widgetKey(array $block): ?string
    {
        $key = $block['type'] ?? $block['widget_key'] ?? $block['widgetKey'] ?? $block['key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    private function publicId(array $block, int $index): string
    {
        $id = $block['public_id'] ?? $block['publicId'] ?? data_get($block, 'data.public_id');

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return 'resource-' . hash('xxh128', (string) ($block['__capell_resource_path'] ?? $index));
    }
}
