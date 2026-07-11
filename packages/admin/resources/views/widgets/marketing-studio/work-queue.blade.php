<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::marketing-studio.work_queue')"
        :description="__('capell-admin::marketing-studio.work_queue_description')"
    >
        @if ($this->items() === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::marketing-studio.empty_work_queue') }}
            </p>
        @else
            <div class="space-y-2">
                @foreach ($this->items() as $item)
                    <a
                        href="{{ $item->resolvedUrl() }}"
                        class="block rounded-lg border border-gray-200 p-3 text-sm hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/10"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <span
                                class="font-medium text-gray-950 dark:text-white"
                            >
                                {{ $item->resolvedLabel() }}
                            </span>
                            @if ($item->resolvedBadge() !== null)
                                <span
                                    class="bg-warning-500/10 text-warning-700 dark:text-warning-300 rounded-md px-1.5 py-0.5 text-xs font-semibold"
                                >
                                    {{ $item->resolvedBadge() }}
                                </span>
                            @endif
                        </div>
                        @if ($item->resolvedDescription() !== null)
                            <p
                                class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                            >
                                {{ $item->resolvedDescription() }}
                            </p>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
