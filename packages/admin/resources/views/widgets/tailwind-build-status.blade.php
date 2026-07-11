<x-filament-widgets::widget>
    <x-filament::section heading="Tailwind build status">
        @php
            $data = $this->data;
        @endphp

        <div class="space-y-4">
            {{-- Summary badges --}}
            <div class="flex flex-wrap items-center gap-2">
                @if ($data->freshCount > 0)
                    <span
                        class="rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-900/40 dark:text-green-300"
                    >
                        {{ $data->freshCount }} fresh
                    </span>
                @endif

                @if ($data->staleCount > 0)
                    <span
                        class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                    >
                        {{ $data->staleCount }} stale
                    </span>
                @endif

                @if ($data->neverBuiltCount > 0)
                    <span
                        class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300"
                    >
                        {{ $data->neverBuiltCount }} never built
                    </span>
                @endif

                @if ($data->sites->count() === 0)
                    <span class="text-xs text-gray-400 dark:text-gray-500">
                        No sites found.
                    </span>
                @endif
            </div>

            {{-- Per-site rows --}}
            @if ($data->sites->count() > 0)
                <div class="space-y-1.5">
                    @foreach ($data->sites as $siteRow)
                        <div
                            class="flex items-center gap-2 rounded px-2 py-1.5 text-xs odd:bg-gray-50 dark:odd:bg-gray-800/50"
                        >
                            <span
                                class="flex-1 font-medium text-gray-700 dark:text-gray-200"
                            >
                                {{ $siteRow->siteName }}
                            </span>

                            @if ($siteRow->status === 'fresh')
                                <span
                                    class="shrink-0 rounded bg-green-100 px-1.5 py-0.5 text-green-700 dark:bg-green-900/40 dark:text-green-300"
                                >
                                    fresh
                                </span>
                            @elseif ($siteRow->status === 'stale')
                                <span
                                    class="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                                >
                                    stale
                                </span>
                            @else
                                <span
                                    class="shrink-0 rounded bg-red-100 px-1.5 py-0.5 text-red-700 dark:bg-red-900/40 dark:text-red-300"
                                >
                                    never built
                                </span>
                            @endif

                            @if ($siteRow->lastBuiltAt !== null)
                                <time
                                    datetime="{{ $siteRow->lastBuiltAt }}"
                                    class="shrink-0 text-gray-400 dark:text-gray-500"
                                >
                                    {{ Carbon::parse($siteRow->lastBuiltAt)->diffForHumans() }}
                                </time>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
