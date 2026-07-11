<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.widget_extensions_recently_changed')"
        :description="__('capell-admin::dashboard.widget_extensions_recently_changed_description')"
    >
        <div class="space-y-2">
            @foreach ($this->events as $event)
                <div
                    class="rounded-lg border border-gray-200 bg-gray-50/70 px-3 py-2.5 text-sm dark:border-white/10 dark:bg-white/5"
                >
                    <div class="font-semibold text-gray-950 dark:text-white">
                        {{ $event->packageName }}
                    </div>
                    <div class="mt-1 text-gray-600 dark:text-gray-300">
                        {{ str($event->event)->headline() }}
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
