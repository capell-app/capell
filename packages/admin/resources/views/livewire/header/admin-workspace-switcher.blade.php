@php
    use Capell\Admin\Actions\ResolveFilamentIconAliasAction;
@endphp

<div class="flex items-center gap-2">
    <x-filament::dropdown
        placement="bottom-end"
        width="md"
    >
        <x-slot name="trigger">
            <x-filament::icon-button
                icon="heroicon-o-squares-2x2"
                :label="__('capell-admin::workspace.switcher_tools.open')"
            />
        </x-slot>

        <div class="w-80 p-3">
            <div class="mb-3 flex flex-wrap gap-1">
                @foreach ($workspaces as $candidate)
                    <x-filament::button
                        color="{{ $workspace === $candidate->value ? 'primary' : 'gray' }}"
                        size="xs"
                        wire:click="setWorkspace(@js($candidate->value))"
                        wire:loading.attr="disabled"
                        wire:target="setWorkspace(@js($candidate->value))"
                        wire:loading.attr="aria-busy"
                        aria-pressed="{{ $workspace === $candidate->value ? 'true' : 'false' }}"
                    >
                        {{ $candidate->label() }}
                    </x-filament::button>
                @endforeach
            </div>

            <x-filament::input.wrapper
                wire:loading.attr="aria-busy"
                wire:target="search"
            >
                <label
                    for="admin-workspace-search"
                    class="sr-only"
                >
                    {{ __('capell-admin::workspace.switcher_tools.search') }}
                </label>
                <x-filament::input
                    id="admin-workspace-search"
                    wire:model.live.debounce.150ms="search"
                    aria-label="{{ __('capell-admin::workspace.switcher_tools.search') }}"
                    :placeholder="__('capell-admin::workspace.switcher_tools.search')"
                />
            </x-filament::input.wrapper>

            <p
                class="sr-only"
                role="status"
                aria-live="polite"
                wire:loading.delay
                wire:target="search"
            >
                {{ __('capell-admin::workspace.switcher_tools.loading') }}
            </p>

            @if ($this->pinnedItems())
                <p class="mt-3 text-xs font-medium text-gray-500">{{ __('capell-admin::workspace.switcher_tools.pinned') }}</p>
                @foreach ($this->pinnedItems() as $item)
                    <div
                        wire:key="workspace-pinned-{{ $item->key }}"
                        class="mt-1 flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                    >
                        <a
                            class="flex min-w-0 flex-1 items-center gap-2"
                            href="{{ $item->url }}"
                            wire:click="recordVisit(@js($item->key))"
                            wire:navigate
                        >
                            @if ($item->icon !== null)
                                @svg(ResolveFilamentIconAliasAction::run($item->icon), 'h-4 w-4 flex-shrink-0 text-gray-400')
                            @else
                                <span class="h-4 w-4 flex-shrink-0"></span>
                            @endif
                            <span class="truncate">{{ $item->label }}</span>
                        </a>
                        <button
                            type="button"
                            wire:click="togglePin(@js($item->key))"
                            wire:loading.attr="disabled"
                            wire:target="togglePin(@js($item->key))"
                            wire:loading.attr="aria-busy"
                            aria-pressed="true"
                            aria-label="{{ __('capell-admin::workspace.switcher_tools.unpin') }}"
                        >
                            <span
                                wire:loading.remove
                                wire:target="togglePin(@js($item->key))"
                                >★</span
                            >
                            <span
                                wire:loading
                                wire:target="togglePin(@js($item->key))"
                                aria-hidden="true"
                                >…</span
                            >
                        </button>
                    </div>
                @endforeach
            @endif

            @if ($this->recentItems())
                <p class="mt-3 text-xs font-medium text-gray-500">{{ __('capell-admin::workspace.switcher_tools.recent') }}</p>
                @foreach ($this->recentItems() as $item)
                    <div
                        wire:key="workspace-recent-{{ $item->key }}"
                        class="mt-1 flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                    >
                        <a
                            class="flex min-w-0 flex-1 items-center gap-2"
                            href="{{ $item->url }}"
                            wire:click="recordVisit(@js($item->key))"
                            wire:navigate
                        >
                            @if ($item->icon !== null)
                                @svg(ResolveFilamentIconAliasAction::run($item->icon), 'h-4 w-4 flex-shrink-0 text-gray-400')
                            @else
                                <span class="h-4 w-4 flex-shrink-0"></span>
                            @endif
                            <span class="truncate">{{ $item->label }}</span>
                        </a>
                        <button
                            type="button"
                            wire:click="togglePin(@js($item->key))"
                            wire:loading.attr="disabled"
                            wire:target="togglePin(@js($item->key))"
                            wire:loading.attr="aria-busy"
                            aria-pressed="{{ $this->isPinned($item->key) ? 'true' : 'false' }}"
                            aria-label="{{ $this->isPinned($item->key) ? __('capell-admin::workspace.switcher_tools.unpin') : __('capell-admin::workspace.switcher_tools.pin') }}"
                        >
                            <span
                                wire:loading.remove
                                wire:target="togglePin(@js($item->key))"
                                >{{ $this->isPinned($item->key) ? '★' : '☆' }}</span
                            >
                            <span
                                wire:loading
                                wire:target="togglePin(@js($item->key))"
                                aria-hidden="true"
                                >…</span
                            >
                        </button>
                    </div>
                @endforeach
            @endif

            <p class="mt-3 text-xs font-medium text-gray-500">{{ $search === '' ? __('capell-admin::workspace.switcher_tools.tools') : __('capell-admin::workspace.switcher_tools.results') }}</p>
            @forelse ($this->toolItems() as $item)
                <div
                    wire:key="workspace-tool-{{ $item->key }}"
                    class="mt-1 flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                >
                    <a
                        class="flex min-w-0 flex-1 items-center gap-2"
                        href="{{ $item->url }}"
                        wire:click="recordVisit(@js($item->key))"
                        wire:navigate
                    >
                        @if ($item->icon !== null)
                            @svg(ResolveFilamentIconAliasAction::run($item->icon), 'h-4 w-4 flex-shrink-0 text-gray-400')
                        @else
                            <span class="h-4 w-4 flex-shrink-0"></span>
                        @endif
                        <span class="truncate">{{ $item->label }}</span>
                    </a>
                    <button
                        type="button"
                        wire:click="togglePin(@js($item->key))"
                        wire:loading.attr="disabled"
                        wire:target="togglePin(@js($item->key))"
                        wire:loading.attr="aria-busy"
                        aria-pressed="{{ $this->isPinned($item->key) ? 'true' : 'false' }}"
                        aria-label="{{ __('capell-admin::workspace.switcher_tools.pin') }}"
                    >
                        <span
                            wire:loading.remove
                            wire:target="togglePin(@js($item->key))"
                            >☆</span
                        >
                        <span
                            wire:loading
                            wire:target="togglePin(@js($item->key))"
                            aria-hidden="true"
                            >…</span
                        >
                    </button>
                </div>
            @empty
                <p class="mt-2 text-sm text-gray-500">{{ __('capell-admin::workspace.switcher_tools.empty') }}</p>
            @endforelse
        </div>
    </x-filament::dropdown>
</div>
