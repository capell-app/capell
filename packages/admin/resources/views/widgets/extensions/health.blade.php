<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.widget_extensions_health')"
        :description="__('capell-admin::dashboard.widget_extensions_health_description')"
    >
        @if ($this->alerts === [])
            <div
                class="border-success-200 bg-success-50 text-success-700 dark:border-success-400/20 dark:bg-success-400/10 dark:text-success-200 rounded-lg border px-3 py-2.5 text-sm font-medium"
            >
                {{ __('capell-admin::dashboard.extensions_health_all_good') }}
            </div>
        @else
            <div class="space-y-2">
                @foreach ($this->alerts as $alert)
                    <div
                        class="rounded-lg border border-gray-200 bg-gray-50/70 px-3 py-2.5 dark:border-white/10 dark:bg-white/5"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div
                                    class="truncate text-sm font-semibold text-gray-950 dark:text-white"
                                >
                                    {{ $alert->title }}
                                </div>
                                <div
                                    class="mt-1 line-clamp-2 text-sm text-gray-600 dark:text-gray-300"
                                >
                                    {{ $alert->message ?: ($alert->requiredAction ?? $alert->packageName) }}
                                </div>
                                @if ($alert->message !== '' && is_string($alert->requiredAction) && trim($alert->requiredAction) !== '' && $alert->developerDetail !== null)
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $alert->requiredAction }}</p>
                                @endif
                                @if (is_string($alert->developerDetail) && trim($alert->developerDetail) !== '')
                                    <details class="mt-1 text-sm">
                                        <summary
                                            class="text-primary-600 dark:text-primary-400 cursor-pointer underline"
                                        >
                                            {{ __('capell-admin::dashboard.extension_developer_detail') }}
                                        </summary>
                                        <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $alert->developerDetail }}</p>
                                    </details>
                                @endif
                            </div>
                            <span
                                @class([
                                'shrink-0 rounded-md px-2 py-1 text-xs font-semibold',
                                'bg-danger-50 text-danger-700 dark:bg-danger-400/10 dark:text-danger-200' => $alert->severity === 'critical',
                                'bg-warning-50 text-warning-700 dark:bg-warning-400/10 dark:text-warning-200' => $alert->severity === 'warning',
                                'bg-info-50 text-info-700 dark:bg-info-400/10 dark:text-info-200' => $alert->severity === 'info',
                            ])
                            >
                                {{ str($alert->severity)->headline() }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
