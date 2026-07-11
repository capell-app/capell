<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.widget_extensions_diagnostics')"
        :description="__('capell-admin::dashboard.widget_extensions_diagnostics_description')"
    >
        <div class="space-y-2">
            @forelse (collect($this->alerts)->take(6) as $alert)
                <div
                    class="rounded-lg border border-gray-200 bg-gray-50/70 px-3 py-2.5 text-sm dark:border-white/10 dark:bg-white/5"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div
                                class="truncate font-semibold text-gray-950 dark:text-white"
                            >
                                {{ $alert->title }}
                            </div>
                            <div
                                class="mt-1 line-clamp-2 text-gray-600 dark:text-gray-300"
                            >
                                {{ $alert->message }}
                            </div>
                            <div
                                class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                            >
                                {{ $alert->packageName }}
                            </div>
                        </div>
                        <span
                            class="shrink-0 rounded-md bg-gray-100 px-2 py-1 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200"
                        >
                            {{ str($alert->severity)->headline() }}
                        </span>
                    </div>
                </div>
            @empty
                <div
                    class="border-success-200 bg-success-50 text-success-700 dark:border-success-400/20 dark:bg-success-400/10 dark:text-success-200 rounded-lg border px-3 py-2.5 text-sm font-medium"
                >
                    {{ __('capell-admin::dashboard.extensions_health_all_good') }}
                </div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
