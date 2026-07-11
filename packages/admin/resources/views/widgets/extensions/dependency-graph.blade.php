<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.widget_extensions_dependency_graph')"
        :description="__('capell-admin::dashboard.widget_extensions_dependency_graph_description')"
    >
        <div class="space-y-2">
            @forelse (collect($this->blockers)->take(6) as $blocker)
                <div
                    class="rounded-lg border border-gray-200 bg-gray-50/70 px-3 py-2.5 text-sm dark:border-white/10 dark:bg-white/5"
                >
                    <div class="font-semibold text-gray-950 dark:text-white">
                        {{ $blocker->packageName }}
                    </div>
                    <div class="mt-1 text-gray-600 dark:text-gray-300">
                        {{ str($blocker->operation)->headline() }}:
                        {{ str($blocker->reason)->replace('_', ' ')->headline() }}
                    </div>
                </div>
            @empty
                <div
                    class="border-success-200 bg-success-50 text-success-700 dark:border-success-400/20 dark:bg-success-400/10 dark:text-success-200 rounded-lg border px-3 py-2.5 text-sm font-medium"
                >
                    {{ __('capell-admin::dashboard.extensions_no_dependency_blockers') }}
                </div>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
