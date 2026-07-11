<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns\Page;

use Capell\Admin\Support\PageUrlPresenter;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

class PageSummaryColumn extends TextColumn
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.page'))
            ->html()
            ->wrap()
            ->toggleable()
            ->formatStateUsing(fn (?string $state, Page $record): HtmlString => $this->summary($record, $state));
    }

    private function summary(Page $page, ?string $state): HtmlString
    {
        $title = e((string) ($state ?? $page->name));
        $ancestors = $this->ancestors($page);
        $url = $this->urlMarkup($page);
        $children = $this->children($page);
        $metadata = $this->metadata($page);
        $health = $this->health($page);

        $html = <<<HTML
            <div class="capell-page-summary min-w-0 space-y-1.5 py-1.5 text-left">
                {$ancestors}
                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="truncate font-medium text-gray-950 dark:text-white">{$title}</span>
                    {$children}
                </div>
                {$url}
                <div class="flex flex-wrap gap-1 text-xs">
                    {$metadata}
                    {$health}
                </div>
            </div>
        HTML;

        return new HtmlString($html);
    }

    private function urlMarkup(Page $page): string
    {
        $pageUrl = $this->pageUrl($page);

        if (! $pageUrl instanceof PageUrl) {
            return '';
        }

        $label = e(str($pageUrl->url)->limit(72)->toString());
        $fullUrl = $this->fullUrl($pageUrl);

        if ($fullUrl === null) {
            return <<<HTML
                <div class="truncate font-mono text-xs text-gray-500 dark:text-gray-400">{$label}</div>
            HTML;
        }

        return <<<HTML
            <a href="{$fullUrl}" target="_blank" class="block truncate font-mono text-xs text-primary-600 hover:underline dark:text-primary-400">{$label}</a>
        HTML;
    }

    private function children(Page $page): string
    {
        if ($page->hasPageHierarchy() === false) {
            return '';
        }

        $childrenCount = (int) $page->getAttribute('children_count');

        if ($childrenCount <= 0) {
            return '';
        }

        $label = e(trans_choice('capell-admin::table.page_children_short', $childrenCount, [
            'count' => $childrenCount,
        ]));

        return <<<HTML
            <span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-normal text-gray-600 dark:bg-gray-800 dark:text-gray-300">{$label}</span>
        HTML;
    }

    private function ancestors(Page $page): string
    {
        if (! $page->relationLoaded('ancestors')) {
            $page->loadMissing('ancestors');
        }

        if ($page->ancestors->isEmpty()) {
            return '';
        }

        $ancestors = $page->ancestors
            ->pluck('name')
            ->map(fn (string $name): string => e($name))
            ->join(' &raquo; ');

        return <<<HTML
            <div class="truncate text-xs text-gray-500 dark:text-gray-400">{$ancestors}</div>
        HTML;
    }

    private function metadata(Page $page): string
    {
        if (! $page->relationLoaded('layout')) {
            $page->loadMissing('layout');
        }

        if (! $page->relationLoaded('blueprint')) {
            $page->loadMissing('blueprint');
        }

        return collect($this->metadataItems($page))
            ->filter(fn (?string $value): bool => filled($value))
            ->map(fn (string $value): string => $this->chip($value))
            ->implode('');
    }

    /**
     * @return list<string|null>
     */
    private function metadataItems(Page $page): array
    {
        return [
            $this->layoutLabel($page),
            $this->typeLabel($page),
        ];
    }

    private function health(Page $page): string
    {
        return collect([
            $this->pageUrl($page) instanceof PageUrl ? null : __('capell-admin::table.page_health_missing_url'),
            $this->hasTitle($page) ? null : __('capell-admin::table.page_health_missing_title'),
            $this->pageLayout($page) instanceof Layout ? null : __('capell-admin::table.page_health_missing_layout'),
        ])
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): string => is_string($value) ? $this->warningChip($value) : '')
            ->implode('');
    }

    private function hasTitle(Page $page): bool
    {
        if (! $page->relationLoaded('translation')) {
            $page->loadMissing('translation');
        }

        $title = $page->translation?->title;

        return is_string($title) && filled($title);
    }

    private function chip(string $label): string
    {
        $label = e($label);

        return '<span class="rounded-md bg-gray-100 px-1.5 py-0.5 text-gray-600 dark:bg-gray-800 dark:text-gray-300">' . $label . '</span>';
    }

    private function warningChip(string $label): string
    {
        $tooltip = e($label);
        $label = e($label);

        return <<<HTML
            <span class="rounded-md bg-warning-50 px-1.5 py-0.5 text-warning-700 ring-1 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-300 dark:ring-warning-400/20" x-tooltip.raw="{$tooltip}" data-page-summary-health="true">{$label}</span>
        HTML;
    }

    private function layoutLabel(Page $page): ?string
    {
        $layoutName = $this->pageLayout($page)?->name;

        return filled($layoutName)
            ? (string) __('capell-admin::table.page_meta_layout_value', ['layout' => $layoutName])
            : null;
    }

    private function typeLabel(Page $page): ?string
    {
        $typeName = $page->blueprint->name;

        if (! is_string($typeName) || $typeName === '' || $typeName === 'capell::generic.default') {
            return null;
        }

        return $typeName;
    }

    private function pageUrl(Page $page): ?PageUrl
    {
        if (! $page->relationLoaded('pageUrls')) {
            $page->loadMissing('pageUrls.siteDomain');
        }

        $pageUrl = $page->pageUrls->first();

        if ($pageUrl instanceof PageUrl) {
            return $pageUrl;
        }

        if (! $page->relationLoaded('pageUrl')) {
            $page->loadMissing('pageUrl.siteDomain');
        }

        $pageUrl = $page->pageUrl;

        return $pageUrl instanceof PageUrl && $pageUrl->exists ? $pageUrl : null;
    }

    private function pageLayout(Page $page): ?Layout
    {
        if (! $page->relationLoaded('layout')) {
            $page->loadMissing('layout');
        }

        $layout = $page->getRelation('layout');

        return $layout instanceof Layout ? $layout : null;
    }

    private function fullUrl(PageUrl $pageUrl): ?string
    {
        $fullUrl = PageUrlPresenter::fullUrl($pageUrl);

        return is_string($fullUrl) ? e($fullUrl) : null;
    }
}
