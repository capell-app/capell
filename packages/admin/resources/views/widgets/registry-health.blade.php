<x-filament-widgets::widget>
    <x-filament::section heading="Registry health">
        <div class="space-y-4">
            @foreach ($this->data->sections as $section)
                <details class="group">
                    <summary
                        class="flex cursor-pointer items-center justify-between gap-2 py-1 text-sm font-medium"
                    >
                        <span>{{ $section->name }}</span>
                        <span
                            class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                        >
                            {{ $section->count }}
                        </span>
                    </summary>

                    <div class="mt-2 space-y-1 pl-2">
                        @forelse ($section->entries as $entry)
                            <div
                                class="flex items-center justify-between gap-2 rounded px-2 py-1 text-xs odd:bg-gray-50 dark:odd:bg-gray-800/50"
                            >
                                <span
                                    class="font-mono text-gray-700 dark:text-gray-200"
                                >
                                    {{ $entry->class }}
                                </span>
                                <div class="flex shrink-0 items-center gap-2">
                                    <span
                                        class="rounded bg-blue-100 px-1.5 py-0.5 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"
                                    >
                                        {{ $entry->sourcePackage }}
                                    </span>
                                    @if ($entry->autoDiscovered)
                                        <span
                                            class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                                        >
                                            auto
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="px-2 py-1 text-xs text-gray-400">
                                None registered
                            </p>
                        @endforelse
                    </div>
                </details>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
