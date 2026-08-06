<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.analytics_recent_activity')"
    >
        @if ($this->data()->recentActivity === [])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::dashboard.analytics_no_activity') }}
            </p>
        @else
            <ul class="space-y-2 text-sm">
                @foreach ($this->data()->recentActivity as $entry)
                    <li class="flex justify-between gap-4">
                        <span class="truncate">{{ $entry['label'] }}</span>
                        <span class="text-gray-500 dark:text-gray-400">
                            {{ trans_choice('capell-admin::dashboard.analytics_activity_count', $entry['count'], ['count' => $entry['count']]) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
