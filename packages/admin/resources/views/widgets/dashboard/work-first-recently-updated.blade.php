<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('capell-admin::dashboard.work_first_recently_updated')"
    >
        <p class="text-xs text-gray-600 dark:text-gray-400">{{ trans_choice('capell-admin::dashboard.work_first_page_total', $this->data->total, ['count' => $this->data->total]) }}</p>
        @forelse ($this->data->items as $item)
            <div class="py-2 text-sm">
                @if ($item->editUrl)
                    <a
                        class="font-medium hover:underline"
                        href="{{ $item->editUrl }}"
                        >{{ $item->title }}</a
                    >
                @else
                    <span class="font-medium">{{ $item->title }}</span>
                @endif
                <p class="text-gray-600 dark:text-gray-400">
                    {{ $item->siteName }} · {{ $item->editor }}
                    @if ($item->updatedAt !== null)
                        ·
                        <time
                            datetime="{{ $item->updatedAt }}"
                            title="{{ $item->updatedAt }}"
                            >{{ \Carbon\CarbonImmutable::parse($item->updatedAt)->diffForHumans() }}</time
                        >
                    @endif
                </p>
            </div>
        @empty
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_no_recent_updates') }}</p>
        @endforelse
        @if (\Capell\Admin\Filament\Resources\Pages\PageResource::canViewAny())
            <a
                class="mt-3 inline-block text-sm hover:underline"
                href="{{ \Capell\Admin\Filament\Resources\Pages\PageResource::getUrl() }}"
                >{{ __('capell-admin::dashboard.work_first_view_all_pages') }}</a
            >
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
