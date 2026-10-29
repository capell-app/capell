<x-filament-widgets::widget wire:poll.300s>
    <x-filament::section
        :heading="__('capell-admin::dashboard.work_first_page_views')"
    >
        @if (in_array($this->data->status, ['available', 'stale'], true))
            @if ($this->data->status === 'stale')
                <p class="text-sm font-medium">{{ __('capell-admin::dashboard.work_first_analytics_stale') }}</p>
                <p class="text-xs">{{ __('capell-admin::dashboard.work_first_reporting_period', ['start' => $this->data->periodStart, 'end' => \Carbon\CarbonImmutable::parse($this->data->periodEnd, 'UTC')->subDay()->toDateString(), 'timezone' => 'UTC']) }}</p>
            @endif
            <p class="text-2xl font-semibold">{{ number_format($this->data->current) }}</p>
            @if ($this->data->previous !== null)
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_previous_views', ['count' => number_format($this->data->previous)]) }}</p>
            @endif
            @if (count($this->data->trend) > 1)
                @php
                    $points = $this->data->trend;
                    $peak = max(1, max(array_column($points, 'count')));
                    $lastIndex = count($points) - 1;
                    $coordinates = collect($points)->map(fn ($point, $index) => round($index * 100 / $lastIndex, 2) . ',' . round(38 - ($point['count'] * 36 / $peak), 2))->implode(' ');
                @endphp
                <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="mt-3 h-16 w-full text-primary-600 dark:text-primary-400" aria-hidden="true">
                    <polyline
                        points="{{ $coordinates }}"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.5"
                        vector-effect="non-scaling-stroke"
                    />
                </svg>
                <details class="mt-2 text-xs text-gray-600 dark:text-gray-400">
                    <summary class="cursor-pointer">
                        {{ __('capell-admin::dashboard.work_first_chart_data') }}
                    </summary>
                    <table class="mt-2 w-full text-left">
                        <thead>
                            <tr>
                                <th scope="col">
                                    {{ __('capell-admin::dashboard.work_first_day') }}
                                </th>
                                <th scope="col">
                                    {{ __('capell-admin::dashboard.work_first_page_views') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($points as $point)
                                <tr>
                                    <td>{{ $point['day'] }}</td>
                                    <td>
                                        {{ number_format($point['count']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif
            <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_page_views_source') }}</p>
            @if ($this->data->lastUpdated !== null)
                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_last_updated', ['date' => $this->data->lastUpdated]) }}</p>
            @endif
        @elseif ($this->data->status === 'disconnected')
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_analytics_disconnected') }}</p>
            @if (\Capell\Admin\Filament\Pages\SettingsPage::canAccess())
                <a
                    class="text-sm hover:underline"
                    href="{{ \Capell\Admin\Filament\Pages\SettingsPage::getUrl() }}"
                    >{{ __('capell-admin::dashboard.work_first_analytics_settings') }}</a
                >
            @endif
        @elseif ($this->data->status === 'failed')
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_analytics_failed') }}</p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_analytics_insufficient') }}</p>
            @if ($this->data->sitesRequested > 1)
                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_analytics_coverage', ['covered' => $this->data->sitesCovered, 'total' => $this->data->sitesRequested]) }}</p>
            @endif
        @endif
        @if ($this->showVisitorScopeNote())
            <p class="mt-3 text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_visitors_site_only') }}</p>
        @endif
        @if ($this->visitors !== null)
            <div
                class="mt-4 border-t border-gray-200 pt-3 dark:border-white/10"
            >
                @if (in_array($this->visitors->status, ['available', 'stale'], true))
                    @if ($this->visitors->status === 'stale')
                        <p class="text-xs font-medium">{{ __('capell-admin::dashboard.work_first_analytics_stale') }}</p>
                        <p class="text-xs">{{ __('capell-admin::dashboard.work_first_reporting_period', ['start' => $this->visitors->periodStart, 'end' => \Carbon\CarbonImmutable::parse($this->visitors->periodEnd, $this->visitors->timezone)->subDay()->toDateString(), 'timezone' => $this->visitors->timezone]) }}</p>
                    @endif
                    <p class="text-sm">{{ $this->visitors->measure }}: <strong>{{ number_format($this->visitors->current) }}</strong></p>
                    @if ($this->visitors->previous !== null)
                        <p class="text-xs">{{ __('capell-admin::dashboard.work_first_previous_visitors', ['count' => number_format($this->visitors->previous)]) }}</p>
                    @endif
                    <p class="text-xs text-gray-600 dark:text-gray-400">{{ $this->visitors->source }} · {{ $this->visitors->timezone }}</p>
                    @if ($this->visitors->lastUpdated !== null)
                        <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_last_updated', ['date' => $this->visitors->lastUpdated]) }}</p>
                    @endif
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_visitors_unavailable') }}</p>
                    @if ($this->visitors->status === 'disconnected' && \Capell\Admin\Filament\Pages\SettingsPage::canAccess())
                        <a
                            class="text-sm hover:underline"
                            href="{{ \Capell\Admin\Filament\Pages\SettingsPage::getUrl() }}"
                            >{{ __('capell-admin::dashboard.work_first_analytics_settings') }}</a
                        >
                    @endif
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
