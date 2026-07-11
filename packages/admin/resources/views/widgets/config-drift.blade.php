<x-filament-widgets::widget>
    <x-filament::section heading="Config drift">
        @php
            $data = $this->data;
        @endphp

        <div class="space-y-4">
            {{-- All-green banner --}}

            @if ($data->totalDriftCount === 0)
                <div
                    class="flex items-center gap-2 rounded-md bg-green-50 px-3 py-2 text-sm text-green-700 dark:bg-green-900/20 dark:text-green-400"
                >
                    <span class="font-medium">
                        &#10003; No config drift detected
                    </span>
                    <span class="text-green-500 dark:text-green-600">
                        ({{ $data->packagesChecked }}
                        {{ Str::plural('package', $data->packagesChecked) }}
                        checked)
                    </span>
                </div>
            @else
                {{-- Summary badge --}}
                <div class="flex items-center gap-3">
                    <span
                        class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300"
                    >
                        {{ $data->totalDriftCount }}
                        {{ Str::plural('drift', $data->totalDriftCount) }}
                    </span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        across {{ $data->packagesChecked }}
                        {{ Str::plural('package', $data->packagesChecked) }}
                    </span>
                </div>

                {{-- Drift rows --}}
                <div class="space-y-1.5">
                    @foreach ($data->entries as $entry)
                        <div
                            class="flex items-start gap-2 rounded px-2 py-1.5 text-xs odd:bg-gray-50 dark:odd:bg-gray-800/50"
                        >
                            {{-- Package badge --}}
                            <span
                                class="mt-px shrink-0 rounded bg-blue-100 px-1.5 py-0.5 font-medium text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
                            >
                                {{ $entry->package }}
                            </span>

                            {{-- Key path --}}
                            <span
                                class="flex-1 font-mono text-gray-700 dark:text-gray-200"
                            >
                                {{ $entry->keyPath }}
                            </span>

                            {{-- Kind badge --}}
                            @if ($entry->kind === 'missing')
                                <span
                                    class="shrink-0 rounded bg-amber-100 px-1.5 py-0.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                                >
                                    missing
                                </span>
                            @else
                                <span
                                    class="shrink-0 rounded bg-red-100 px-1.5 py-0.5 text-red-700 dark:bg-red-900/40 dark:text-red-300"
                                >
                                    stale
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Footer note --}}
            <div
                class="border-t pt-2 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500"
            >
                Missing = key in shipped config absent from host. Stale = key in
                host config no longer shipped.
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
