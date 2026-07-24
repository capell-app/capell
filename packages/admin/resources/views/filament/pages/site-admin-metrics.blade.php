<x-filament-panels::page>
    <div
        class="space-y-6"
        data-testid="capell-site-admin-metrics"
    >
        @forelse ($this->series() as $series)
            <x-filament::section>
                <x-slot name="heading">
                    {{ $series->label }}
                </x-slot>

                @if ($series->description !== '')
                    <x-slot name="description">
                        {{ $series->description }}
                    </x-slot>
                @endif

                <div class="space-y-5">
                    <p
                        class="text-3xl font-semibold text-gray-950 dark:text-white"
                    >
                        {{ $series->latestValue }}
                    </p>

                    @if ($series->points !== [])
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ __('capell-admin::metrics.dates_with_readings') }}
                        </p>
                        <div
                            class="flex h-32 items-end gap-1"
                            role="img"
                            aria-label="{{ __('capell-admin::metrics.trend_label', ['metric' => $series->label]) }}"
                        >
                            @foreach ($series->points as $point)
                                <div
                                    @class([
                                        'group bg-primary-500 relative min-w-0 flex-1 rounded-t',
                                        'h-1' => $point->heightBucket === 0,
                                        'h-2/12' => $point->heightBucket === 1,
                                        'h-4/12' => $point->heightBucket === 2,
                                        'h-5/12' => $point->heightBucket === 3,
                                        'h-6/12' => $point->heightBucket === 4,
                                        'h-8/12' => $point->heightBucket === 5,
                                        'h-10/12' => $point->heightBucket === 6,
                                        'h-full' => $point->heightBucket === 7,
                                    ])
                                    title="{{ __('capell-admin::metrics.point_label', ['day' => $point->day, 'value' => $point->value]) }}"
                                >
                                    <span class="sr-only">
                                        {{ __('capell-admin::metrics.point_label', ['day' => $point->day, 'value' => $point->value]) }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ __('capell-admin::metrics.no_series_data') }}
                        </p>
                    @endif
                </div>
            </x-filament::section>
        @empty
            <x-filament::section>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('capell-admin::metrics.no_registered_series') }}
                </p>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
