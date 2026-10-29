@php($items = $this->items)
@php($limit = $dashboardSize === 'wide' ? 8 : 5)
<x-filament-widgets::widget>
    <x-filament::section :heading="__('capell-admin::work-first.publishing')">
        <p class="mb-3 text-sm">{{ $this->blockerCount === 0 ? __('capell-admin::work-first.publishing_clear') : trans_choice('capell-admin::work-first.publishing_blockers', $this->blockerCount, ['count' => $this->blockerCount]) }}</p>
        <p class="text-sm text-gray-500">{{ __('capell-admin::work-first.upcoming') }} · {{ count($items) }}</p>
        @if ($items === [])
            <p class="mt-2 text-sm text-gray-500">{{ __('capell-admin::work-first.no_upcoming') }}</p>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/5">
                @foreach (array_slice($items, 0, $limit) as $item)
                    @include('capell-admin::widgets.dashboard.work-first-publishing-row', ['item' => $item])
                @endforeach
            </ul>
            @if (count($items) > $limit)
                <x-filament::modal
                    :id="'publishing-all-' . $this->getId()"
                    width="4xl"
                    :heading="__('capell-admin::work-first.upcoming')"
                >
                    <x-slot name="trigger">
                        <x-filament::button
                            color="gray"
                            size="sm"
                            >{{ __('capell-admin::work-first.view_all_count', ['count' => count($items)]) }}</x-filament::button
                        >
                    </x-slot>
                    <ul class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($items as $item)
                            @include('capell-admin::widgets.dashboard.work-first-publishing-row', ['item' => $item])
                        @endforeach
                    </ul>
                </x-filament::modal>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
