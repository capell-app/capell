<x-filament-widgets::widget>
    <x-filament::section heading="Migrations health">
        @php
            $data = $this->data;
        @endphp

        <div class="space-y-4">
            {{-- All-green banner --}}
            @if ($data->isAllGreen())
                <div
                    class="flex items-center gap-2 rounded-lg bg-green-50 px-3 py-2 dark:bg-green-900/20"
                >
                    <span class="h-2.5 w-2.5 rounded-full bg-green-500"></span>
                    <span
                        class="text-sm font-medium text-green-700 dark:text-green-300"
                    >
                        All migrated
                    </span>
                </div>
            @else
                {{-- Pending migrations --}}
                @if ($data->pendingCount > 0)
                    <details
                        class="group"
                        open
                    >
                        <summary
                            class="flex cursor-pointer items-center justify-between gap-2 py-1 text-sm font-medium"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="h-2 w-2 rounded-full bg-red-500"
                                ></span>
                                Pending migrations
                            </span>
                            <span
                                class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300"
                            >
                                {{ $data->pendingCount }}
                            </span>
                        </summary>

                        <div class="mt-2 space-y-1 pl-4">
                            @foreach ($data->pendingMigrations as $migration)
                                <div
                                    class="rounded px-2 py-1 font-mono text-xs text-gray-700 odd:bg-gray-50 dark:text-gray-200 dark:odd:bg-gray-800/50"
                                >
                                    {{ $migration }}
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif

                {{-- Orphaned registrations --}}
                @if ($data->orphanedCount > 0)
                    <details
                        class="group"
                        open
                    >
                        <summary
                            class="flex cursor-pointer items-center justify-between gap-2 py-1 text-sm font-medium"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="h-2 w-2 rounded-full bg-amber-500"
                                ></span>
                                Orphaned registrations
                            </span>
                            <span
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                            >
                                {{ $data->orphanedCount }}
                            </span>
                        </summary>

                        <div class="mt-2 space-y-2 pl-4">
                            @foreach ($data->orphanedRegistrations as $orphan)
                                <div
                                    class="rounded px-2 py-1.5 text-xs odd:bg-gray-50 dark:odd:bg-gray-800/50"
                                >
                                    <div class="flex items-center gap-2">
                                        <span
                                            class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300"
                                        >
                                            {{ $orphan['package'] }}
                                        </span>
                                        <span
                                            class="font-mono text-gray-700 dark:text-gray-200"
                                        >
                                            {{ $orphan['name'] }}
                                        </span>
                                    </div>
                                    <div
                                        class="mt-0.5 truncate font-mono text-gray-400 dark:text-gray-500"
                                    >
                                        {{ $orphan['expectedPath'] }}
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            @endif

            {{-- Last batch --}}
            @if ($data->lastBatch !== null)
                <div
                    class="border-t pt-2 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500"
                >
                    Last batch: {{ $data->lastBatch }}
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
