<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::marketing-studio.quick_actions')"
        :description="__('capell-admin::marketing-studio.quick_actions_description')"
    >
        @if ($this->groupedActions() === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::marketing-studio.empty_actions') }}
            </p>
        @else
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($this->groupedActions() as $group)
                    <div class="space-y-2">
                        <h3
                            class="text-sm font-semibold text-gray-950 dark:text-white"
                        >
                            {{ $group['section']->label() }}
                        </h3>

                        <div class="space-y-2">
                            @foreach ($group['actions'] as $action)
                                <a
                                    href="{{ $action->resolvedUrl() }}"
                                    class="focus-visible:outline-primary-600 block rounded-lg border border-gray-200 bg-white p-3 text-sm transition hover:bg-gray-50 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10"
                                >
                                    <div
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <span
                                            class="font-medium text-gray-950 dark:text-white"
                                        >
                                            {{ $action->resolvedLabel() }}
                                        </span>

                                        @if ($action->resolvedBadge() !== null)
                                            <span
                                                class="shrink-0 rounded-md bg-gray-950/5 px-1.5 py-0.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200"
                                            >
                                                {{ $action->resolvedBadge() }}
                                            </span>
                                        @endif
                                    </div>

                                    @if ($action->resolvedDescription() !== null)
                                        <p
                                            class="mt-1 text-xs leading-5 text-gray-500 dark:text-gray-400"
                                        >
                                            {{ $action->resolvedDescription() }}
                                        </p>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
