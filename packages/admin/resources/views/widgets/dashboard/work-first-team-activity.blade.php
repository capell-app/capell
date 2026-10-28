@php($items = $this->items)
<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section :heading="__('capell-admin::work-first.activity')">
        @if ($this->pendingItems > 0)
            <x-filament::button
                color="gray"
                size="sm"
                wire:click="applyDashboardUpdates"
                >{{ __('capell-admin::work-first.show_updates', ['count' => $this->pendingItems]) }}</x-filament::button
            >
        @endif
        <x-slot name="afterHeader">
            <a
                href="{{ $this->activityUrl() }}"
                class="text-xs text-primary-600 hover:underline dark:text-primary-400"
                >{{ __('capell-admin::work-first.view_all') }}</a
            >
        </x-slot>
        @if ($items === [])
            <p class="text-sm text-gray-500">{{ __('capell-admin::work-first.activity_empty') }}</p>
        @else
            <ol class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach ($items as $item)
                    <li
                        class="flex items-start gap-3 py-3"
                        wire:key="{{ $item->key }}"
                    >
                        <x-filament::icon
                            icon="heroicon-o-clock"
                            class="mt-0.5 h-4 w-4 shrink-0 text-gray-400"
                        />
                        <div class="min-w-0 flex-1 text-sm">
                            <span class="font-medium">{{ $item->actor }}</span>
                            {{ $item->eventLabel ?? __('capell-admin::work-first.activity_' . $item->event) }}
                            @if ($item->url)
                                <a
                                    href="{{ $item->url }}"
                                    class="font-medium hover:underline"
                                    >{{ $item->title }}</a
                                >
                            @else
                                <span
                                    class="font-medium"
                                    >{{ $item->title }}</span
                                >
                            @endif
                            <p class="mt-1 text-xs text-gray-500">
                                {{ $item->siteName }} ·
                                <time
                                    datetime="{{ $item->occurredAt }}"
                                    title="{{ $item->occurredAt }}"
                                    >{{ \Carbon\CarbonImmutable::parse($item->occurredAt)->diffForHumans() }}</time
                                >
                                @if ($item->changes > 1) ·{{ __('capell-admin::work-first.grouped_changes', ['count' => $item->changes]) }} @endif
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
