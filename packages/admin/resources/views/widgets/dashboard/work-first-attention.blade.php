@php($items = $this->items)
@php($limit = $dashboardSize === 'wide' ? 8 : 5)
<x-filament-widgets::widget
    id="work-first-attention"
    wire:poll.60s
>
    <x-filament::section :heading="__('capell-admin::work-first.attention')">
        @if ($this->pendingItems > 0)
            <x-filament::button
                color="gray"
                size="sm"
                wire:click="applyDashboardUpdates"
                >{{ __('capell-admin::work-first.show_updates', ['count' => $this->pendingItems]) }}</x-filament::button
            >
        @endif
        @if ($issueResolved)
            <p role="status" class="mb-2 text-sm">{{ __('capell-admin::work-first.resolved') }}</p>
        @endif
        @forelse (['urgent' => true, 'improvements' => false] as $label => $urgent)
            @php($group = array_values(array_filter($items, fn ($item) => $item->urgent === $urgent)))
            @if ($group !== [])
                <h3
                    class="mt-2 text-xs font-semibold uppercase tracking-wide text-gray-500"
                >
                    {{ __('capell-admin::work-first.' . $label) }} · {{ count($group) }}
                </h3>
                <ul class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach (array_slice($group, 0, $limit) as $item)
                        @include('capell-admin::widgets.dashboard.work-first-attention-row', ['item' => $item, 'rowContext' => 'all'])
                    @endforeach
                </ul>
            @endif
        @empty
        @endforelse
        @if ($items === [])
            <p class="text-sm text-gray-500">{{ __('capell-admin::work-first.no_attention') }}</p>
        @elseif (count($items) > $limit)
            <x-filament::modal
                :id="'attention-all-' . $this->getId()"
                width="4xl"
                :heading="__('capell-admin::work-first.attention_list')"
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
                        @include('capell-admin::widgets.dashboard.work-first-attention-row', ['item' => $item])
                    @endforeach
                </ul>
            </x-filament::modal>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
