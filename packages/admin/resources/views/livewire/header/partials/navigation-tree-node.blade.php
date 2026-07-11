@php
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;

    $nodeId = (int) $node['id'];
    $siteId = (int) $node['site_id'];
    $isExpanded = $expandedPages[$nodeId] ?? false;
    $hasChildren = (bool) ($node['has_children'] ?? false);
    $typeIcon = is_string($node['type_icon'] ?? null)
        ? $node['type_icon']
        : Heroicon::OutlinedDocumentText->getIconForSize(IconSize::Small);
    $level = max(0, (int) $level);
    $isLast ??= false;
    $indent = max(0, $level - 1) * 1.5;
    $connectorLeft = $indent + 1.125;
    $padding = $indent + 0.5;
    $isSearchMatch = $searchMatchId !== null && (int) $searchMatchId === $nodeId;
@endphp

<div wire:key="header-navigation-node-{{ $nodeId }}-{{ $level }}">
    <div
        class="@class([
            'group relative flex items-center gap-1.5 rounded-lg border border-transparent px-2 py-1.5 text-sm transition hover:border-gray-200 hover:bg-gray-50 hover:shadow-sm hover:shadow-gray-950/5 dark:hover:border-white/10 dark:hover:bg-white/5 dark:hover:shadow-none',
            'border-primary-200 bg-primary-50/70 shadow-primary-950/5 dark:border-primary-500/20 dark:bg-primary-500/10 shadow-sm dark:shadow-none' => $isSearchMatch,
        ])"
        style="padding-left: {{ $padding }}rem"
    >
        @if ($level > 1)
            @for ($lineLevel = 1; $lineLevel < $level - 1; $lineLevel++)
                <span
                    class="pointer-events-none absolute inset-y-0 border-l border-gray-200 group-hover:border-gray-300 dark:border-white/10 dark:group-hover:border-white/20"
                    style="left: {{ (($lineLevel - 1) * 1.5) + 1.125 }}rem"
                    aria-hidden="true"
                ></span>
            @endfor

            <span
                class="{{ $isLast ? 'h-1/2' : 'bottom-0' }} pointer-events-none absolute top-0 border-l border-gray-200 group-hover:border-gray-300 dark:border-white/10 dark:group-hover:border-white/20"
                style="left: {{ $connectorLeft }}rem"
                aria-hidden="true"
            ></span>
            <span
                class="pointer-events-none absolute top-1/2 w-4 border-t border-gray-200 group-hover:border-gray-300 dark:border-white/10 dark:group-hover:border-white/20"
                style="left: {{ $connectorLeft }}rem"
                aria-hidden="true"
            ></span>
        @endif

        @if ($hasChildren && $renderChildren)
            <button
                class="focus-visible:ring-primary-500 relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-white text-gray-400 transition outline-none hover:bg-gray-100 hover:text-gray-700 focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-70 dark:bg-gray-950 dark:hover:bg-white/10 dark:hover:text-gray-200"
                type="button"
                title="{{ $isExpanded ? __('capell-admin::button.collapse') : __('capell-admin::button.expand') }}"
                wire:click="togglePage({{ $nodeId }}, {{ $siteId }})"
                wire:loading.attr="disabled"
                wire:target="togglePage({{ $nodeId }}, {{ $siteId }})"
            >
                <x-filament::loading-indicator
                    class="text-primary-500 h-4 w-4"
                    wire:loading.delay
                    wire:target="togglePage({{ $nodeId }}, {{ $siteId }})"
                />
                <span
                    wire:loading.remove
                    wire:target="togglePage({{ $nodeId }}, {{ $siteId }})"
                >
                    @svg(($isExpanded ? Heroicon::OutlinedChevronDown : Heroicon::OutlinedChevronRight)->getIconForSize(IconSize::Small), 'h-4 w-4')
                </span>
            </button>
        @else
            <span
                class="relative z-10 flex h-7 w-7 shrink-0 items-center justify-center"
                aria-hidden="true"
            >
                <span
                    class="h-1.5 w-1.5 rounded-full bg-gray-200 group-hover:bg-gray-300 dark:bg-white/10 dark:group-hover:bg-white/20"
                ></span>
            </span>
        @endif

        <a
            class="@class([
                'hover:text-primary-600 focus:text-primary-600 focus-visible:ring-primary-500 dark:hover:text-primary-400 dark:focus:text-primary-400 relative z-10 flex min-w-0 flex-1 items-center gap-2 rounded-md px-1.5 py-1 text-gray-700 outline-none focus-visible:ring-2 focus-visible:ring-offset-2 dark:text-gray-200 dark:focus-visible:ring-offset-gray-900',
                'text-primary-700 dark:text-primary-300 font-semibold' => $isSearchMatch,
            ])"
            href="{{ $node['edit_url'] }}"
        >
            @svg($typeIcon, $isSearchMatch ? 'text-primary-500 dark:text-primary-300 h-4 w-4 shrink-0' : 'h-4 w-4 shrink-0 text-gray-400 group-hover:text-gray-500 dark:group-hover:text-gray-300')
            <span class="flex min-w-0 flex-1 items-baseline gap-2">
                <span class="truncate">
                    {{ $node['name'] }}
                </span>
                @if (is_string($node['public_url'] ?? null))
                    <span
                        class="min-w-0 shrink truncate text-xs font-normal text-gray-400 dark:text-gray-500"
                        title="{{ $node['public_url'] }}"
                    >
                        {{ $node['public_url'] }}
                    </span>
                @endif
            </span>
        </a>

        <a
            class="hover:text-primary-600 focus:text-primary-600 focus-visible:ring-primary-500 dark:hover:text-primary-400 dark:focus:text-primary-400 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-gray-400 opacity-0 transition outline-none group-hover:opacity-100 focus:opacity-100 focus-visible:ring-2"
            href="{{ $node['edit_url'] }}"
            title="{{ __('capell-admin::button.edit_page') }}"
            x-tooltip.raw="{{ __('capell-admin::button.edit_page') }}"
        >
            @svg(Heroicon::OutlinedPencilSquare->getIconForSize(IconSize::Small), 'h-4 w-4')
        </a>

        @if (is_string($node['public_url'] ?? null))
            <a
                class="hover:text-primary-600 focus:text-primary-600 focus-visible:ring-primary-500 dark:hover:text-primary-400 dark:focus:text-primary-400 flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-gray-400 opacity-0 transition outline-none group-hover:opacity-100 focus:opacity-100 focus-visible:ring-2"
                href="{{ $node['public_url'] }}"
                target="_blank"
                rel="noopener noreferrer"
                title="{{ __('capell-admin::button.visit_page') }}"
                x-tooltip.raw="{{ __('capell-admin::button.visit_page') }}"
            >
                @svg(Heroicon::OutlinedArrowTopRightOnSquare->getIconForSize(IconSize::Small), 'h-4 w-4')
            </a>
        @endif
    </div>

    @if ($renderChildren && $isExpanded)
        @php
            $branch = $pageBranches[$nodeId] ?? ['items' => [], 'has_more' => false, 'next_page' => null];
        @endphp

        @foreach ($branch['items'] as $child)
            @include('capell-admin::livewire.header.partials.navigation-tree-node', [
                'node' => $child,
                'level' => $level + 1,
                'isLast' => $loop->last,
                'searchMatchId' => null,
                'renderChildren' => true,
            ])
        @endforeach

        @include('capell-admin::livewire.header.partials.navigation-tree-load-more', [
            'branch' => $branch,
            'action' => 'loadMoreChildren(' . $nodeId . ', ' . $siteId . ')',
            'target' => 'loadMoreChildren(' . $nodeId . ', ' . $siteId . ')',
            'level' => $level + 1,
        ])
    @endif
</div>
