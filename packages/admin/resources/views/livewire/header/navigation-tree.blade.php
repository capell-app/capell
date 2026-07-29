@php
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;
@endphp

<div class="flex items-center">
    <x-filament::dropdown
        placement="left-start"
        width="xl"
    >
        <x-slot name="trigger">
            <button
                @class([
                    'fi-dropdown-list-item fi-dropdown-list-item-color-gray flex w-full items-center gap-2 rounded-md p-2 text-sm whitespace-nowrap transition-colors duration-75 outline-none hover:bg-gray-50 focus:bg-gray-50 disabled:pointer-events-none disabled:opacity-70 dark:hover:bg-white/5 dark:focus:bg-white/5' => $rowTrigger,
                    'text-primary-600 hover:text-primary-700 focus:text-primary-700 flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full focus:outline-none' => ! $rowTrigger,
                ])
                type="button"
                title="{{ __('capell-admin::button.navigation_tree_tooltip') }}"
                x-tooltip.raw="{{ __('capell-admin::button.navigation_tree_tooltip') }}"
                wire:click="loadTree"
            >
                @svg(Heroicon::OutlinedQueueList->getIconForSize(IconSize::Small), $rowTrigger ? 'fi-dropdown-list-item-icon h-5 w-5 text-gray-400 dark:text-gray-500' : 'h-4 w-4')

                @if ($rowTrigger)
                    {{ __('capell-admin::button.browse_pages') }}
                @endif
            </button>
        </x-slot>

        <div class="overflow-hidden rounded-xl">
            <div
                class="border-b border-gray-200 bg-gray-50/80 p-3 dark:border-white/10 dark:bg-white/[0.04]"
            >
                <label
                    class="sr-only"
                    for="capell-header-navigation-search"
                >
                    {{ __('capell-admin::navigation_tree.search_label') }}
                </label>
                <div class="relative">
                    <input
                        id="capell-header-navigation-search"
                        class="focus:border-primary-500 focus:ring-primary-500 dark:focus:border-primary-500 block h-10 w-full rounded-lg border-gray-200 bg-white ps-3 pe-10 text-sm text-gray-900 shadow-sm shadow-gray-950/5 transition duration-75 outline-none placeholder:text-gray-400 focus:ring-1 dark:border-white/10 dark:bg-white/5 dark:text-white dark:placeholder:text-gray-500"
                        type="search"
                        placeholder="{{ __('capell-admin::navigation_tree.search_placeholder') }}"
                        wire:model.live.debounce.400ms="search"
                    />

                    <div
                        class="pointer-events-none absolute inset-y-0 right-0 flex items-center pe-3 text-gray-400"
                    >
                        <x-filament::loading-indicator
                            class="text-primary-500 h-4 w-4"
                            wire:loading.delay
                            wire:target="search"
                        />
                        <span
                            wire:loading.remove
                            wire:target="search"
                        >
                            @svg(Heroicon::OutlinedMagnifyingGlass->getIconForSize(IconSize::Small), 'h-4 w-4')
                        </span>
                    </div>
                </div>
            </div>

            <div
                class="max-h-[70vh] overflow-y-auto bg-white p-2 dark:bg-gray-950"
            >
                <div
                    class="flex min-h-52 flex-col items-center justify-center gap-3 rounded-lg bg-gray-50/60 px-6 py-10 text-center dark:bg-white/[0.03]"
                    wire:loading.delay
                    wire:target="loadTree"
                >
                    <div
                        class="bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-300 flex h-12 w-12 items-center justify-center rounded-full"
                    >
                        <x-filament::loading-indicator class="h-7 w-7" />
                    </div>
                    <span
                        class="text-sm font-medium text-gray-700 dark:text-gray-200"
                    >
                        {{ __('capell-admin::navigation_tree.loading') }}
                    </span>
                </div>

                <div
                    wire:loading.remove
                    wire:target="loadTree"
                >
                    @if (! $loaded)
                        <div
                            class="py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                        >
                            {{ __('capell-admin::navigation_tree.open_to_load') }}
                        </div>
                    @elseif ($this->isSearching())
                        @if ($searchResults['paths'] === [])
                            <div
                                class="py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                            >
                                {{ __('capell-admin::navigation_tree.no_search_results') }}
                            </div>
                        @else
                            <div class="space-y-2">
                                @foreach ($searchResults['paths'] as $path)
                                    <div
                                        class="rounded-lg border border-gray-200 bg-gray-50/50 p-2 shadow-sm shadow-gray-950/5 dark:border-white/10 dark:bg-white/[0.03] dark:shadow-none"
                                        wire:key="header-navigation-search-path-{{ $path['key'] }}"
                                    >
                                        @if (count($sites) > 1)
                                            <div
                                                class="mb-1 flex min-w-0 items-baseline gap-2 px-2 text-xs font-semibold text-gray-600 dark:text-gray-300"
                                            >
                                                <span class="truncate">
                                                    {{ $path['site']['name'] }}
                                                </span>
                                                @if (is_string($path['site']['public_url'] ?? null))
                                                    <span
                                                        class="min-w-0 shrink truncate font-normal text-gray-400 dark:text-gray-500"
                                                        title="{{ $path['site']['public_url'] }}"
                                                    >
                                                        {{ $path['site']['public_url'] }}
                                                    </span>
                                                @endif
                                            </div>
                                        @endif

                                        @foreach ($path['nodes'] as $level => $node)
                                            @include('capell-admin::livewire.header.partials.navigation-tree-node', [
                                                'node' => $node,
                                                'level' => $level,
                                                'isLast' => $loop->last,
                                                'searchMatchId' => $path['match_id'],
                                                'renderChildren' => false,
                                            ])
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>

                            @if ($searchResults['has_more'])
                                <button
                                    class="text-primary-600 hover:bg-primary-50 focus-visible:ring-primary-500 dark:text-primary-400 dark:hover:bg-primary-950/40 mt-2 flex w-full items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition outline-none focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70"
                                    type="button"
                                    wire:click="loadMoreSearchResults"
                                    wire:loading.attr="disabled"
                                    wire:target="loadMoreSearchResults"
                                >
                                    <x-filament::loading-indicator
                                        class="h-4 w-4"
                                        wire:loading.delay
                                        wire:target="loadMoreSearchResults"
                                    />
                                    <span
                                        wire:loading.remove
                                        wire:target="loadMoreSearchResults"
                                    >
                                        {{ __('capell-admin::navigation_tree.load_more') }}
                                    </span>
                                </button>
                            @endif
                        @endif
                    @elseif ($sites === [])
                        <div
                            class="py-8 text-center text-sm text-gray-500 dark:text-gray-400"
                        >
                            {{ __('capell-admin::navigation_tree.no_pages') }}
                        </div>
                    @else
                        <div class="space-y-1">
                            @foreach ($sites as $site)
                                @php
                                    $siteId = (int) $site['id'];
                                    $siteExpanded = $expandedSites[$siteId] ?? false;
                                    $branch = $rootBranches[$siteId] ?? ['items' => [], 'has_more' => false, 'next_page' => null];
                                @endphp

                                <div
                                    wire:key="header-navigation-site-{{ $siteId }}"
                                >
                                    <div
                                        class="group flex items-center gap-1.5 rounded-lg border border-transparent px-2 py-1.5 text-sm font-semibold text-gray-800 transition hover:border-gray-200 hover:bg-gray-50 hover:shadow-sm hover:shadow-gray-950/5 dark:text-gray-100 dark:hover:border-white/10 dark:hover:bg-white/5 dark:hover:shadow-none"
                                    >
                                        <button
                                            class="focus-visible:ring-primary-500 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-gray-400 transition outline-none hover:bg-white hover:text-gray-700 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70 dark:hover:bg-white/10 dark:hover:text-gray-200"
                                            type="button"
                                            title="{{ $siteExpanded ? __('capell-admin::button.collapse') : __('capell-admin::button.expand') }}"
                                            aria-label="{{ $siteExpanded ? __('capell-admin::button.collapse') : __('capell-admin::button.expand') }}"
                                            aria-expanded="{{ $siteExpanded ? 'true' : 'false' }}"
                                            wire:click="toggleSite({{ $siteId }})"
                                            wire:loading.attr="disabled"
                                            wire:target="toggleSite({{ $siteId }})"
                                        >
                                            <x-filament::loading-indicator
                                                class="text-primary-500 h-4 w-4"
                                                wire:loading.delay
                                                wire:target="toggleSite({{ $siteId }})"
                                            />
                                            <span
                                                wire:loading.remove
                                                wire:target="toggleSite({{ $siteId }})"
                                            >
                                                @svg(($siteExpanded ? Heroicon::OutlinedChevronDown : Heroicon::OutlinedChevronRight)->getIconForSize(IconSize::Small), 'h-4 w-4')
                                            </span>
                                        </button>
                                        @if (is_string($site['edit_url'] ?? null))
                                            <a
                                                class="hover:text-primary-600 focus:text-primary-600 focus-visible:ring-primary-500 dark:hover:text-primary-400 dark:focus:text-primary-400 flex min-w-0 flex-1 items-center gap-2 rounded-md px-1.5 py-1 outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900"
                                                href="{{ $site['edit_url'] }}"
                                            >
                                                @svg(Heroicon::OutlinedGlobeAlt->getIconForSize(IconSize::Small), 'text-primary-500 dark:text-primary-400 h-4 w-4 shrink-0')
                                                <span
                                                    class="flex min-w-0 flex-1 items-baseline gap-2"
                                                >
                                                    <span class="truncate">
                                                        {{ $site['name'] }}
                                                    </span>
                                                    @if (is_string($site['public_url'] ?? null))
                                                        <span
                                                            class="min-w-0 shrink truncate text-xs font-normal text-gray-400 dark:text-gray-500"
                                                            title="{{ $site['public_url'] }}"
                                                        >
                                                            {{ $site['public_url'] }}
                                                        </span>
                                                    @endif
                                                </span>
                                            </a>
                                        @else
                                            <span
                                                class="flex min-w-0 flex-1 items-center gap-2 px-1.5 py-1"
                                            >
                                                @svg(Heroicon::OutlinedGlobeAlt->getIconForSize(IconSize::Small), 'text-primary-500 dark:text-primary-400 h-4 w-4 shrink-0')
                                                <span
                                                    class="flex min-w-0 flex-1 items-baseline gap-2"
                                                >
                                                    <span class="truncate">
                                                        {{ $site['name'] }}
                                                    </span>
                                                    @if (is_string($site['public_url'] ?? null))
                                                        <span
                                                            class="min-w-0 shrink truncate text-xs font-normal text-gray-400 dark:text-gray-500"
                                                            title="{{ $site['public_url'] }}"
                                                        >
                                                            {{ $site['public_url'] }}
                                                        </span>
                                                    @endif
                                                </span>
                                            </span>
                                        @endif
                                        @if (is_string($site['edit_url'] ?? null))
                                            <a
                                                class="hover:text-primary-600 focus:text-primary-600 focus-visible:ring-primary-500 dark:hover:text-primary-400 dark:focus:text-primary-400 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-gray-400 opacity-0 transition outline-none group-hover:opacity-100 focus:opacity-100 focus-visible:ring-2"
                                                href="{{ $site['edit_url'] }}"
                                                title="{{ __('capell-admin::button.edit_site') }}"
                                                x-tooltip.raw="{{ __('capell-admin::button.edit_site') }}"
                                            >
                                                @svg(Heroicon::OutlinedPencilSquare->getIconForSize(IconSize::Small), 'h-4 w-4')
                                            </a>
                                        @endif
                                    </div>

                                    @if ($siteExpanded)
                                        <div class="mt-1">
                                            @foreach ($branch['items'] as $node)
                                                @include('capell-admin::livewire.header.partials.navigation-tree-node', [
                                                    'node' => $node,
                                                    'level' => 1,
                                                    'isLast' => $loop->last,
                                                    'searchMatchId' => null,
                                                    'renderChildren' => true,
                                                ])
                                            @endforeach

                                            @include('capell-admin::livewire.header.partials.navigation-tree-load-more', [
                                                'branch' => $branch,
                                                'action' => 'loadMoreRoot(' . $siteId . ')',
                                                'target' => 'loadMoreRoot(' . $siteId . ')',
                                            ])
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </x-filament::dropdown>
</div>
