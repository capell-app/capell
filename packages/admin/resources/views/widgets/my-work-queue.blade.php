<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.widget_my_work_queue')"
    >
        <div class="@container divide-y divide-gray-200 dark:divide-white/10">
            @foreach ($this->data->items as $item)
                <div class="py-2 text-sm first:pt-0 last:pb-0">
                    <div
                        class="@sm:flex-row @sm:items-center @sm:gap-3 flex min-w-0 flex-col items-start gap-1.5"
                    >
                        @switch($item->kind)
                            @case('draft')
                                <span
                                    class="shrink-0 rounded-md bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-300"
                                >
                                    {{ __('capell-admin::dashboard.work_queue_kind_draft') }}
                                </span>

                                @break
                            @case('awaiting_approval')
                                <span
                                    class="bg-warning-100 text-warning-800 dark:bg-warning-400/10 dark:text-warning-300 shrink-0 rounded-md px-1.5 py-0.5 text-xs font-medium"
                                >
                                    {{ __('capell-admin::dashboard.work_queue_kind_awaiting_approval') }}
                                </span>

                                @break
                            @case('scheduled')
                                <span
                                    class="bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-300 shrink-0 rounded-md px-1.5 py-0.5 text-xs font-medium"
                                >
                                    {{ __('capell-admin::dashboard.work_queue_kind_scheduled') }}
                                </span>

                                @break
                        @endswitch
                        @if ($item->editUrl)
                            <a
                                href="{{ $item->editUrl }}"
                                class="block w-full max-w-full min-w-0 flex-1 truncate font-medium text-gray-950 hover:underline dark:text-white"
                            >
                                {{ $item->title }}
                            </a>
                        @else
                            <span
                                class="block w-full max-w-full min-w-0 flex-1 truncate font-medium text-gray-950 dark:text-white"
                            >
                                {{ $item->title }}
                            </span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
