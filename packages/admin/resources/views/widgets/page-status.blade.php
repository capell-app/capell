<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.widget_capell_overview')"
    >
        <div class="@container">
            <div class="@md:grid-cols-2 grid gap-2 @4xl:grid-cols-3">
                @foreach ($this->stats as $stat)
                    @if ($stat->url !== null)
                        <a
                            href="{{ $stat->url }}"
                            class="hover:border-primary-300 hover:bg-primary-50/60 dark:hover:border-primary-500/50 dark:hover:bg-primary-500/10 group flex min-h-20 items-start justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50/70 px-3 py-2.5 transition dark:border-white/10 dark:bg-white/5"
                        >
                            @include('capell-admin::widgets.partials.capell-overview-stat-row', ['stat' => $stat])
                        </a>
                    @else
                        <div
                            class="flex min-h-20 items-start justify-between gap-3 rounded-lg border border-gray-200 bg-gray-50/70 px-3 py-2.5 dark:border-white/10 dark:bg-white/5"
                        >
                            @include('capell-admin::widgets.partials.capell-overview-stat-row', ['stat' => $stat])
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
