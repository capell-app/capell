@php
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Js;

    $modalId = 'admin-workspaces-' . $this->getId();
    $searchId = 'admin-workspace-search-' . $this->getId();
@endphp

<div class="flex items-center gap-2">
    <x-filament::modal
        :id="$modalId"
        width="2xl"
        teleport="body"
        :icon="Heroicon::OutlinedSquares2x2"
        :heading="__('capell-admin::workspace.switcher_tools.modal_heading')"
        :description="__('capell-admin::workspace.switcher_tools.modal_description')"
    >
        <x-slot name="trigger">
            <x-filament::icon-button
                icon="heroicon-o-squares-2x2"
                :label="__('capell-admin::workspace.switcher_tools.open')"
                :tooltip="__('capell-admin::workspace.switcher_tools.open')"
            />
        </x-slot>

        <div class="flex flex-col gap-4">
            <div>
                <div
                    class="flex flex-wrap gap-2"
                    role="group"
                    aria-label="{{ __('capell-admin::workspace.switcher_tools.groups_label') }}"
                >
                    @foreach ($workspaces as $candidate)
                        <x-filament::button
                            color="{{ $workspace === $candidate->value ? 'primary' : 'gray' }}"
                            size="sm"
                            :icon="$candidate->icon()"
                            wire:click="setWorkspace({{ Js::from($candidate->value) }})"
                            wire:loading.attr="disabled"
                            wire:target="setWorkspace({{ Js::from($candidate->value) }})"
                            aria-pressed="{{ $workspace === $candidate->value ? 'true' : 'false' }}"
                        >
                            {{ $candidate->label() }}
                        </x-filament::button>
                    @endforeach
                </div>

                <p
                    class="mt-2 text-sm text-gray-600 dark:text-gray-400"
                    aria-live="polite"
                >
                    <span class="font-medium text-gray-950 dark:text-white"
                        >{{ __('capell-admin::workspace.switcher_tools.selected_label', ['workspace' => $current->label()]) }}:</span
                    >
                    {{ $current->description() }}
                </p>
            </div>

            <x-filament::input.wrapper
                :prefix-icon="Heroicon::OutlinedMagnifyingGlass"
                wire:loading.attr="aria-busy"
                wire:target="search"
            >
                <label
                    for="{{ $searchId }}"
                    class="sr-only"
                >
                    {{ __('capell-admin::workspace.switcher_tools.search') }}
                </label>
                <x-filament::input
                    id="{{ $searchId }}"
                    type="search"
                    wire:model.live.debounce.150ms="search"
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

            <div class="flex max-h-96 flex-col gap-4 overflow-y-auto">
                @if ($this->pinnedItems() !== [])
                    <section>
                        <h3
                            class="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400"
                        >
                            {{ __('capell-admin::workspace.switcher_tools.pinned') }}
                        </h3>
                        <ul>
                            @foreach ($this->pinnedItems() as $item)
                                @include('capell-admin::livewire.header.partials.workspace-tool-row', ['item' => $item, 'section' => 'pinned', 'modalId' => $modalId])
                            @endforeach
                        </ul>
                    </section>
                @endif

                @if ($this->recentItems() !== [])
                    <section>
                        <h3
                            class="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400"
                        >
                            {{ __('capell-admin::workspace.switcher_tools.recent') }}
                        </h3>
                        <ul>
                            @foreach ($this->recentItems() as $item)
                                @include('capell-admin::livewire.header.partials.workspace-tool-row', ['item' => $item, 'section' => 'recent', 'modalId' => $modalId])
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section>
                    <h3
                        class="mb-1 text-xs font-semibold text-gray-500 dark:text-gray-400"
                    >
                        {{ $search === '' ? __('capell-admin::workspace.switcher_tools.tools') : __('capell-admin::workspace.switcher_tools.results') }}
                    </h3>
                    @if ($this->toolItems() !== [])
                        <ul>
                            @foreach ($this->toolItems() as $item)
                                @include('capell-admin::livewire.header.partials.workspace-tool-row', ['item' => $item, 'section' => 'tool', 'modalId' => $modalId])
                            @endforeach
                        </ul>
                    @elseif ($this->pinnedItems() === [] && $this->recentItems() === [])
                        <p class="p-2 text-sm text-gray-500 dark:text-gray-400">{{ $this->emptyMessage() }}</p>
                    @endif
                </section>
            </div>
        </div>

        <x-slot name="footer">
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('capell-admin::workspace.switcher_tools.footer_hint') }}</p>
        </x-slot>
    </x-filament::modal>
</div>
