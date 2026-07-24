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
                        <div
                            class="flex h-32 items-end gap-1"
                            role="img"
                            aria-label="{{ __('capell-admin::metrics.trend_label', ['metric' => $series->label]) }}"
                        >
                            @foreach ($series->points as $point)
                                <div
                                    @class([
                                        'group bg-primary-500 relative min-w-0 flex-1 rounded-t',
                                        $point->heightClass,
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
