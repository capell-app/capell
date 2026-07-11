<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Tables\Columns\Page;

use Capell\Admin\Filament\Components\Tables\Columns\BadgeableColumn;
use Capell\Admin\Support\PageUrlPresenter;
use Capell\Core\Contracts\Pageable;
use Capell\Core\Models\Page;
use Capell\Core\Models\PageUrl;
use Capell\Core\Models\SiteDomain;
use Closure;
use Exception;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class PageNameColumn extends BadgeableColumn
{
    protected Closure|bool $hasChildren = true;

    protected Closure|bool $hasAncestors = true;

    protected Closure|string|null $nameUrl = null;

    protected string $resolveRecordKey = 'id';

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::table.name'))
            ->extraCellAttributes(['class' => 'filament-table-icon-xs'])
            ->recordKey('id')
            ->searchable()
            ->wrap()
            ->weight(FontWeight::Medium)
            ->html()
            ->color(function (Model $record): ?string {
                $page = $this->resolvePageRecord($record);

                return $page->deleted_at !== null ? 'danger' : null;
            })
            ->formatStateUsing(function (string|HtmlString|null $state, Model $record): HtmlString {
                $page = $this->resolvePageRecord($record);

                $html = $state instanceof HtmlString ? $state->toHtml() : e((string) $state);

                $url = $this->getNameUrl($record);

                if ($url !== null) {
                    $html = sprintf(
                        '<a href="%s" class="hover:underline">%s</a>',
                        e($url),
                        $html,
                    );
                }

                if ($page->hasPageHierarchy() && $this->hasChildren()) {
                    if ($page->getAttributeValue('children_count') === null) {
                        $page->loadCount(['children']);
                    }

                    if ($page->children_count > 0) {
                        $tooltip = e((string) __('capell-admin::generic.total_children', ['total' => $page->children_count]));
                        $childrenCount = e((string) $page->children_count);

                        $html .= sprintf(' <span x-tooltip.raw="%s" title="%s" class="font-normal">(%s)</span>', $tooltip, $tooltip, $childrenCount);
                    }
                }

                return new HtmlString($html);
            });
    }

    public function ancestorsDescription(): static
    {
        return $this->description(function (Model $record): ?HtmlString {
            if (! $this->hasAncestors()) {
                return null;
            }

            $page = $this->resolvePageRecord($record);

            return $this->getAncestorsHtml($page);
        });
    }

    public function ancestorsPrefix(): static
    {
        return $this->getStateUsing(function (Model $record): HtmlString|string {
            if (! $this->hasAncestors()) {
                return (string) $this->resolvePageRecord($record)->getAttributeValue('name');
            }

            $page = $this->resolvePageRecord($record);
            $ancestors = $this->getAncestorsHtml($page);

            if (! $ancestors instanceof HtmlString) {
                return (string) $page->getAttributeValue('name');
            }

            return new HtmlString(sprintf('%s &raquo; %s', $ancestors->toHtml(), e((string) $page->getAttributeValue('name'))));
        });
    }

    public function urlDescription(): static
    {
        return $this->description(function (Model $record): ?HtmlString {
            $page = $this->resolvePageRecord($record);
            $pageUrl = $this->resolveRenderablePageUrl($page);

            if (! $pageUrl instanceof PageUrl || $pageUrl->url === '/') {
                return null;
            }

            $fullUrl = PageUrlPresenter::fullUrl($pageUrl);

            if ($fullUrl === null) {
                return null;
            }

            $fullUrl = e($fullUrl);
            $url = e($pageUrl->url);

            $html = sprintf('<a href="%s" class="text-xs text-gray-500 dark:text-gray-400" target="_blank">%s</a>', $fullUrl, $url);

            return new HtmlString($html);
        });
    }

    public function withTypeIcon(): static
    {
        $this->icon(function (Model $record): ?string {
            $page = $this->resolvePageRecord($record);

            if ($page->blueprint === null) {
                return null;
            }

            return $page->blueprint->admin['icon'] ?? null;
        });

        return $this;
    }

    public function withUrl(): static
    {
        return $this->urlDescription();
    }

    public function withParents(): static
    {
        return $this->description(function (Model $record): ?HtmlString {
            $page = $this->resolvePageRecord($record);

            if ($page->hasPageHierarchy() === false) {
                return null;
            }

            if ($page->ancestors->isEmpty()) {
                return null;
            }

            $html = "<span class='text-xs text-gray-500 dark:text-gray-400'>"
                . $page->ancestors->map(fn (Page $ancestor): string => e($ancestor->name))->implode(' ')
                . '</span>';

            return new HtmlString($html);
        });
    }

    public function hasChildren(): bool
    {
        return $this->evaluate($this->hasChildren);
    }

    public function children(bool $hasChildren = true): static
    {
        $this->hasChildren = $hasChildren;

        return $this;
    }

    public function hasAncestors(): bool
    {
        return $this->evaluate($this->hasAncestors);
    }

    public function ancestors(bool $hasAncestors = true): static
    {
        $this->hasAncestors = $hasAncestors;

        return $this;
    }

    public function nameUrl(Closure|string|null $url): static
    {
        $this->nameUrl = $url;

        return $this;
    }

    public function resolveRecordKey(string $resolveRecordKey): static
    {
        $this->resolveRecordKey = $resolveRecordKey;

        return $this;
    }

    private function getNameUrl(Model $record): ?string
    {
        $url = $this->evaluate($this->nameUrl, [
            'record' => $record,
        ]);

        if (! is_string($url) || in_array($url, ['', '0'], true)) {
            return null;
        }

        return $url;
    }

    /**
     * @param  Pageable<Page>  $page
     */
    private function getAncestorsHtml(Pageable $page): ?HtmlString
    {
        $ancestors = $page instanceof Model && $page->relationLoaded('ancestors')
            ? $page->getRelation('ancestors')
            : $page->ancestors()->get();

        if (! $ancestors instanceof EloquentCollection) {
            return null;
        }

        if ($ancestors->isEmpty()) {
            return null;
        }

        return new HtmlString(
            $ancestors
                ->pluck('name')
                ->map(fn (string $name): string => e($name))
                ->join(' &raquo; '),
        );
    }

    /**
     * @param  Pageable<Page>  $page
     */
    private function resolveRenderablePageUrl(Pageable $page): ?PageUrl
    {
        if (! $page instanceof Page) {
            return null;
        }

        $pageUrl = $page->pageUrl;

        if (! $pageUrl->exists) {
            return null;
        }

        if (! $pageUrl->relationLoaded('siteDomain')) {
            $pageUrl->load('siteDomain');
        }

        if (! $pageUrl->siteDomain instanceof SiteDomain) {
            return null;
        }

        return $pageUrl;
    }

    /**
     * @return Pageable<Page>
     */
    private function resolvePageRecord(Model $record): Pageable
    {
        if ($record instanceof Pageable) {
            return $record;
        }

        $page = $record->getRelation('pageable');

        if ($page instanceof Pageable) {
            return $page;
        }

        throw new Exception('Page relation not found.');
    }
}
