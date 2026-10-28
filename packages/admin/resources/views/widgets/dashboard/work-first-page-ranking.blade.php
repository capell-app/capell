<x-filament-widgets::widget>
    <x-filament::section
        :heading="$this->isRising() ? __('capell-admin::dashboard.work_first_rising_pages') : __('capell-admin::dashboard.work_first_popular_pages')"
    >
        @if (in_array($this->data->status, ['available', 'stale'], true))
            @if ($this->data->status === 'stale')
                <p class="text-sm font-medium">{{ __('capell-admin::dashboard.work_first_analytics_stale') }}</p>
            @endif
            <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_last_updated', ['date' => $this->data->lastUpdated]) }}</p>
            <p class="text-xs text-gray-600 dark:text-gray-400">{{ $this->data->measure }} · {{ $this->data->source }} · {{ $this->data->timezone }} · {{ __('capell-admin::dashboard.work_first_page_total', ['count' => $this->data->total]) }}</p>
            @forelse ($this->data->items as $item)
                <div class="py-2 text-sm">
                    <p class="font-medium">{{ $item->title }}</p>
                    <p class="text-gray-600 dark:text-gray-400">
                        {{ $item->siteName }} · {{ number_format($item->current) }}
                        @if ($this->isRising() && $item->previous !== null)
                            · {{ __('capell-admin::dashboard.work_first_rankings_previous', ['count' => number_format($item->previous)]) }}
                        @endif
                        @if ($this->isRising() && $item->isNew && $item->previous === 0)
                            · {{ __('capell-admin::dashboard.work_first_new_page') }}
                        @elseif ($this->isRising() && $item->previous > 0)
                            · {{ __('capell-admin::dashboard.work_first_rankings_growth', ['count' => number_format($item->current - $item->previous), 'percent' => number_format(($item->current - $item->previous) / $item->previous * 100, 1)]) }}
                        @endif
                    </p>
                    @if ($item->publicUrl)
                        <a
                            class="hover:underline"
                            href="{{ $item->publicUrl }}"
                            >{{ __('capell-admin::dashboard.work_first_view_page') }}</a
                        >
                    @endif
                    @if ($item->editUrl)
                        <a
                            class="ml-2 hover:underline"
                            href="{{ $item->editUrl }}"
                            >{{ __('capell-admin::dashboard.work_first_edit_page') }}</a
                        >
                    @endif
                    @if ($item->performanceUrl)
                        <a
                            class="ml-2 hover:underline"
                            href="{{ $item->performanceUrl }}"
                            >{{ __('capell-admin::dashboard.work_first_page_performance') }}</a
                        >
                    @endif
                </div>
            @empty
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_rankings_empty') }}</p>
            @endforelse
            @if ($this->data->reportUrl)
                <a
                    class="mt-2 inline-block text-sm hover:underline"
                    href="{{ $this->data->reportUrl }}"
                    >{{ __('capell-admin::dashboard.work_first_full_report') }}</a
                >
            @endif
            <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_rankings_exclusions') }}</p>
        @elseif ($this->data->status === 'failed')
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_analytics_failed') }}</p>
        @else
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_rankings_unavailable') }}</p>
            @if ($this->data->sitesRequested > 1)
                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_analytics_coverage', ['covered' => $this->data->sitesCovered, 'total' => $this->data->sitesRequested]) }}</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
