<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.widget_recently_published')"
    >
        <div class="@container divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($this->data->items as $item)
                <div
                    class="@sm:flex-row @sm:items-center @sm:justify-between @sm:gap-4 flex min-w-0 flex-col gap-1 py-2 text-sm first:pt-0 last:pb-0"
                >
                    <div class="w-full min-w-0 flex-1">
                        @if ($item->editUrl)
                            <a
                                href="{{ $item->editUrl }}"
                                class="block truncate font-medium text-gray-950 hover:underline dark:text-white"
                            >
                                {{ $item->title }}
                            </a>
                        @else
                            <span
                                class="block truncate font-medium text-gray-950 dark:text-white"
                            >
                                {{ $item->title }}
                            </span>
                        @endif

                        <div
                            class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400"
                        >
                            {{ $item->siteName }}
                        </div>
                    </div>

                    @if ($item->publishedAt)
                        <time
                            datetime="{{ $item->publishedAt }}"
                            class="@sm:shrink-0 text-xs text-gray-400 dark:text-gray-500"
                        >
                            {{ Carbon::parse($item->publishedAt)->diffForHumans() }}
                        </time>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
