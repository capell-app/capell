<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::marketing-studio.advanced')"
        :description="__('capell-admin::marketing-studio.advanced_description')"
    >
        @if ($this->actions() === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::marketing-studio.empty_advanced') }}
            </p>
        @else
            <div class="grid gap-2 md:grid-cols-3">
                @foreach ($this->actions() as $action)
                    <a
                        href="{{ $action->resolvedUrl() }}"
                        class="rounded-lg border border-gray-200 p-3 text-sm hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/10"
                    >
                        <span class="font-medium text-gray-950 dark:text-white">
                            {{ $action->resolvedLabel() }}
                        </span>
                        @if ($action->resolvedDescription() !== null)
                            <p
                                class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                            >
                                {{ $action->resolvedDescription() }}
                            </p>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
