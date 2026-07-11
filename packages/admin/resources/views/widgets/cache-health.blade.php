<x-filament-widgets::widget>
    <x-filament::section heading="Cache health">
        {{-- Site selector --}}
        @if (count($this->sites) > 1)
            <div class="mb-4">
                <select
                    wire:model.live="selectedSiteId"
                    class="focus:border-primary-500 focus:ring-primary-500 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
                >
                    @foreach ($this->sites as $siteOption)
                        <option value="{{ $siteOption['id'] }}">
                            {{ $siteOption['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        @if ($this->data === null)
            <p class="text-sm text-gray-400">No site selected.</p>
        @else
            @php
                $data = $this->data;
                $total = $data->totalEnabledUrls;
                $pct = $total > 0 ? (int) round($data->cachedCount / $total * 100) : 0;
            @endphp

            {{-- Big cached percentage bar --}}
            @if ($total > 0)
                <div class="mb-4 flex items-center gap-4">
                    <div class="flex-1">
                        <div
                            class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700"
                        >
                            <div
                                class="{{ $pct === 100 ? 'bg-success-500' : ($pct >= 50 ? 'bg-warning-500' : 'bg-danger-500') }} h-2 rounded-full transition-all"
                                style="width: {{ $pct }}%"
                            ></div>
                        </div>
                    </div>
                    <span
                        class="shrink-0 text-sm font-medium text-gray-700 tabular-nums dark:text-gray-300"
                    >
                        {{ $data->cachedCount }} / {{ $total }} &mdash;
                        {{ $pct }}%
                    </span>
                </div>
            @endif

            {{-- Three count cards --}}
            <div class="mb-4 grid grid-cols-3 gap-3">
                <div
                    class="bg-success-50 dark:bg-success-900/20 rounded-lg p-3 text-center"
                >
                    <div
                        class="text-success-700 dark:text-success-400 text-2xl font-bold tabular-nums"
                    >
                        {{ $data->cachedCount }}
                    </div>
                    <div
                        class="text-success-600 dark:text-success-500 mt-1 text-xs"
                    >
                        Cached
                    </div>
                </div>

                <div
                    class="bg-warning-50 dark:bg-warning-900/20 rounded-lg p-3 text-center"
                >
                    <div
                        class="text-warning-700 dark:text-warning-400 text-2xl font-bold tabular-nums"
                    >
                        {{ $data->staleCount }}
                    </div>
                    <div
                        class="text-warning-600 dark:text-warning-500 mt-1 text-xs"
                    >
                        Stale
                    </div>
                </div>

                <div
                    class="{{ $data->missingCount > 0 ? 'bg-danger-50 dark:bg-danger-900/20' : 'bg-gray-50 dark:bg-gray-800' }} rounded-lg p-3 text-center"
                >
                    <div
                        class="{{ $data->missingCount > 0 ? 'text-danger-700 dark:text-danger-400' : 'text-gray-500 dark:text-gray-400' }} text-2xl font-bold tabular-nums"
                    >
                        {{ $data->missingCount }}
                    </div>
                    <div
                        class="{{ $data->missingCount > 0 ? 'text-danger-600 dark:text-danger-500' : 'text-gray-400 dark:text-gray-500' }} mt-1 text-xs"
                    >
                        Missing
                    </div>
                </div>
            </div>

            {{-- Last warmed timestamp --}}
            <div class="mb-4 text-xs text-gray-400 dark:text-gray-500">
                @if ($data->lastWarmedAt)
                    Last warmed: {{ $data->lastWarmedAt }}
                @else
                        Not yet warmed.
                @endif
            </div>

            {{-- Warm cache button --}}
            <div>
                <button
                    wire:click="warmCache"
                    wire:loading.attr="disabled"
                    class="bg-primary-600 hover:bg-primary-700 focus:ring-primary-500 inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium text-white shadow-sm transition focus:ring-2 focus:ring-offset-2 focus:outline-none disabled:opacity-60 dark:focus:ring-offset-gray-900"
                >
                    <span
                        wire:loading.remove
                        wire:target="warmCache"
                    >
                        Warm cache
                    </span>
                    <span
                        wire:loading
                        wire:target="warmCache"
                    >
                        Warming…
                    </span>
                </button>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
