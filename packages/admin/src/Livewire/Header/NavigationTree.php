<?php

declare(strict_types=1);

namespace Capell\Admin\Livewire\Header;

use Capell\Admin\Actions\HeaderNavigation\ListHeaderNavigationSitesAction;
use Capell\Admin\Actions\HeaderNavigation\LoadHeaderNavigationChildrenAction;
use Capell\Admin\Actions\HeaderNavigation\SearchHeaderNavigationPagesAction;
use Capell\Admin\Data\HeaderNavigation\HeaderNavigationSiteData;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class NavigationTree extends Component
{
    public const int PER_PAGE = 10;

    public bool $loaded = false;

    public bool $singleSite = false;

    public bool $rowTrigger = false;

    public string $search = '';

    /** @var list<array{id: int, name: string, edit_url: ?string, public_url: ?string}> */
    public array $sites = [];

    /** @var array<int, bool> */
    public array $expandedSites = [];

    /** @var array<int, bool> */
    public array $expandedPages = [];

    /** @var array<int, array{items: list<array<string, mixed>>, page: int, per_page: int, has_more: bool, next_page: ?int}> */
    public array $rootBranches = [];

    /** @var array<int, array{items: list<array<string, mixed>>, page: int, per_page: int, has_more: bool, next_page: ?int}> */
    public array $pageBranches = [];

    /** @var array{paths: list<array<string, mixed>>, page: int, per_page: int, has_more: bool, next_page: ?int} */
    public array $searchResults = [
        'paths' => [],
        'page' => 1,
        'per_page' => self::PER_PAGE,
        'has_more' => false,
        'next_page' => null,
    ];

    protected string $view = 'capell-admin::livewire.header.navigation-tree';

    public function loadTree(): void
    {
        if ($this->loaded) {
            $this->expandSingleSiteRoot();

            return;
        }

        $this->loaded = true;
        $this->sites = array_values(array_map(
            fn (HeaderNavigationSiteData $site): array => $site->toRecord(),
            ListHeaderNavigationSitesAction::run($this->actor()),
        ));

        $this->singleSite = count($this->sites) === 1;

        if ($this->singleSite) {
            $this->expandSingleSiteRoot(loadBranch: true);
        }
    }

    public function toggleSite(int $siteId): void
    {
        if ($this->expandedSites[$siteId] ?? false) {
            unset($this->expandedSites[$siteId]);

            return;
        }

        if (! isset($this->rootBranches[$siteId])) {
            $this->loadRootBranch($siteId);
        }

        $this->expandedSites[$siteId] = true;
    }

    public function togglePage(int $pageId, int $siteId): void
    {
        if ($this->expandedPages[$pageId] ?? false) {
            unset($this->expandedPages[$pageId]);

            return;
        }

        if (! isset($this->pageBranches[$pageId])) {
            $this->pageBranches[$pageId] = LoadHeaderNavigationChildrenAction::run(
                actor: $this->actor(),
                mode: LoadHeaderNavigationChildrenAction::MODE_PAGE_CHILDREN,
                siteId: $siteId,
                parentId: $pageId,
                page: 1,
                perPage: self::PER_PAGE,
            )->toRecord();
        }

        $this->expandedPages[$pageId] = true;
    }

    public function loadMoreRoot(int $siteId): void
    {
        $nextPage = $this->rootBranches[$siteId]['next_page'] ?? null;

        if (! is_int($nextPage)) {
            return;
        }

        $nextBranch = LoadHeaderNavigationChildrenAction::run(
            actor: $this->actor(),
            mode: LoadHeaderNavigationChildrenAction::MODE_SITE_ROOT,
            siteId: $siteId,
            page: $nextPage,
            perPage: self::PER_PAGE,
        )->toRecord();

        $this->rootBranches[$siteId] = $this->mergeBranch($this->rootBranches[$siteId], $nextBranch);
    }

    public function loadMoreChildren(int $pageId, int $siteId): void
    {
        $nextPage = $this->pageBranches[$pageId]['next_page'] ?? null;

        if (! is_int($nextPage)) {
            return;
        }

        $nextBranch = LoadHeaderNavigationChildrenAction::run(
            actor: $this->actor(),
            mode: LoadHeaderNavigationChildrenAction::MODE_PAGE_CHILDREN,
            siteId: $siteId,
            parentId: $pageId,
            page: $nextPage,
            perPage: self::PER_PAGE,
        )->toRecord();

        $this->pageBranches[$pageId] = $this->mergeBranch($this->pageBranches[$pageId], $nextBranch);
    }

    public function loadMoreSearchResults(): void
    {
        $nextPage = $this->searchResults['next_page'] ?? null;

        if (! is_int($nextPage)) {
            return;
        }

        /** @var array{paths: list<array<string, mixed>>, page: int, per_page: int, has_more: bool, next_page: ?int} $nextResults */
        $nextResults = SearchHeaderNavigationPagesAction::run(
            actor: $this->actor(),
            search: $this->search,
            page: $nextPage,
            perPage: self::PER_PAGE,
        )->toRecord();

        $this->searchResults = [
            'paths' => [
                ...$this->searchResults['paths'],
                ...$nextResults['paths'],
            ],
            'page' => $nextResults['page'],
            'per_page' => $nextResults['per_page'],
            'has_more' => $nextResults['has_more'],
            'next_page' => $nextResults['next_page'],
        ];
    }

    public function updatedSearch(): void
    {
        if (! $this->isSearching()) {
            $this->searchResults = [
                'paths' => [],
                'page' => 1,
                'per_page' => self::PER_PAGE,
                'has_more' => false,
                'next_page' => null,
            ];

            return;
        }

        $this->searchResults = SearchHeaderNavigationPagesAction::run(
            actor: $this->actor(),
            search: $this->search,
            page: 1,
            perPage: self::PER_PAGE,
        )->toRecord();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->updatedSearch();
    }

    public function isSearching(): bool
    {
        return mb_strlen(trim($this->search)) >= 2;
    }

    public function render(): View
    {
        /** @var view-string $view */
        $view = $this->view;

        return view($view);
    }

    private function loadRootBranch(int $siteId): void
    {
        $this->rootBranches[$siteId] = LoadHeaderNavigationChildrenAction::run(
            actor: $this->actor(),
            mode: LoadHeaderNavigationChildrenAction::MODE_SITE_ROOT,
            siteId: $siteId,
            page: 1,
            perPage: self::PER_PAGE,
        )->toRecord();
    }

    private function expandSingleSiteRoot(bool $loadBranch = false): void
    {
        if (! $this->singleSite || ! isset($this->sites[0]['id'])) {
            return;
        }

        $siteId = $this->sites[0]['id'];

        if ($loadBranch && ! isset($this->rootBranches[$siteId])) {
            $this->loadRootBranch($siteId);
        }

        $this->expandedSites[$siteId] = true;
    }

    /**
     * @param  array{items: list<array<string, mixed>>, page: int, per_page: int, has_more: bool, next_page: ?int}  $currentBranch
     * @param  array{items: list<array<string, mixed>>, page: int, per_page: int, has_more: bool, next_page: ?int}  $nextBranch
     * @return array{items: list<array<string, mixed>>, page: int, per_page: int, has_more: bool, next_page: ?int}
     */
    private function mergeBranch(array $currentBranch, array $nextBranch): array
    {
        return [
            ...$nextBranch,
            'items' => [
                ...$currentBranch['items'],
                ...$nextBranch['items'],
            ],
        ];
    }

    private function actor(): ?Authenticatable
    {
        $actor = Filament::auth()->user();

        return $actor instanceof Authenticatable ? $actor : null;
    }
}
