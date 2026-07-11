<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::marketing-studio.timeline')"
        :description="__('capell-admin::marketing-studio.timeline_description')"
    >
        @if ($this->items() === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::marketing-studio.empty_timeline') }}
            </p>
        @else
            <ol class="space-y-3">
                @foreach ($this->items() as $item)
                    <li
                        class="before:bg-primary-500 relative pl-5 text-sm before:absolute before:top-2 before:left-0 before:h-2 before:w-2 before:rounded-full"
                    >
                        <a
                            href="{{ $item->resolvedUrl() }}"
                            class="font-medium text-gray-950 hover:underline dark:text-white"
                        >
                            {{ $item->resolvedLabel() }}
                        </a>
                        <p
                            class="mt-1 text-xs text-gray-500 dark:text-gray-400"
                        >
                            {{ $item->resolvedDescription() ?? $item->section->label() }}
                        </p>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
