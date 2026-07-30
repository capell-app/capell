<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.widget_extensions_runtime_compatibility')"
        :description="__('capell-admin::dashboard.widget_extensions_runtime_compatibility_description')"
    >
        <div class="space-y-2">
            @foreach (collect($this->checks)->take(6) as $check)
                <div
                    class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50/70 px-3 py-2.5 text-sm dark:border-white/10 dark:bg-white/5"
                >
                    <div class="min-w-0">
                        <div
                            class="truncate font-semibold text-gray-950 dark:text-white"
                        >
                            {{ $check->packageName }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $check->message ?? implode(', ', $check->requirements) }}
                        </div>
                    </div>
                    <span
                        class="shrink-0 rounded-md bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200"
                    >
                        {{ str($check->state)->headline() }}
                    </span>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
