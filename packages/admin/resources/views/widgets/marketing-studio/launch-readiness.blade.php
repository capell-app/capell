<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::marketing-studio.launch_readiness')"
        :description="__('capell-admin::marketing-studio.launch_readiness_description')"
    >
        @if ($this->checks() === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::marketing-studio.empty_launch_readiness') }}
            </p>
        @else
            <div class="space-y-3">
                @foreach ($this->checks() as $check)
                    <div
                        class="flex items-start justify-between gap-3 rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10"
                    >
                        <div>
                            <a
                                href="{{ $check->resolvedUrl() }}"
                                class="font-medium text-gray-950 hover:underline dark:text-white"
                            >
                                {{ $check->resolvedLabel() }}
                            </a>
                            @if ($check->resolvedDescription() !== null)
                                <p
                                    class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                                >
                                    {{ $check->resolvedDescription() }}
                                </p>
                            @endif
                        </div>
                        <span
                            class="bg-success-500/10 text-success-700 dark:text-success-300 rounded-md px-1.5 py-0.5 text-xs font-semibold"
                        >
                            {{ __('capell-admin::marketing-studio.ready') }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
