@php($visibleIds = array_map(fn ($item) => $item->pageId, $this->workRows))
@php($visibleAssignments = count(array_filter($this->assignments->items, fn ($item) => in_array($item->pageId, $visibleIds, true))))
@php($visibleDrafts = count(array_filter($this->data->items, fn ($item) => in_array($item->pageId, $visibleIds, true))))
<x-filament-widgets::widget wire:poll.60s>
    <x-filament::section
        :heading="__('capell-admin::dashboard.work_first_drafts_heading')"
    >
        @if ($this->assignments->available && $this->assignments->total > 0)
            <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_assigned_total', ['count' => $this->assignments->total]) }}</p>
        @endif
        @if ($this->data->total > 0)
            <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_drafts_total', ['count' => $this->data->total]) }}</p>
        @endif
        @if ($this->pendingWork > 0)
            <p class="mb-2 text-sm">
                {{ __('capell-admin::dashboard.work_first_new_items', ['count' => $this->pendingWork]) }}
                <button
                    type="button"
                    class="font-medium underline"
                    wire:click="applyWorkUpdates"
                >
                    {{ __('capell-admin::dashboard.work_first_apply_updates') }}
                </button>
            </p>
        @endif
        @forelse ($this->workRows as $item)
            <div class="py-2 text-sm">
                @if ($item->editUrl)
                    <a
                        href="{{ $item->editUrl }}"
                        class="font-medium hover:underline"
                        >{{ $item->title }}</a
                    >
                @else
                    <span>{{ $item->title }}</span>
                @endif
                <p class="text-gray-600 dark:text-gray-400">
                    {{ match ($item->reason) {
                        'changes_requested' => __('capell-admin::dashboard.work_first_reason_requested_changes'),
                        'draft' => __('capell-admin::dashboard.work_first_reason_draft'),
                        default => __('capell-admin::dashboard.work_first_reason_assigned'),
                    } }} · {{ $item->siteName }}
                </p>
                @if ($item->deadline !== null)
                    <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_deadline') }} <time datetime="{{ $item->deadline }}"
                        title="{{ $item->deadline }}">{{ \Carbon\CarbonImmutable::parse($item->deadline)->diffForHumans() }}</time> · {{ $item->timezone ?? __('capell-admin::dashboard.work_first_utc_offset', ['offset' => \Carbon\CarbonImmutable::parse($item->deadline)->format('P')]) }}</p>
                @endif
            </div>
        @empty
            @if ($this->pendingWork === 0 && $this->assignments->available)
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_no_work') }}</p>
            @elseif ($this->pendingWork === 0 && ! $this->assignments->available)
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_drafts_empty') }}</p>
            @endif
        @endforelse
        @if ($this->assignments->listUrl !== null && $this->assignments->total > $visibleAssignments)
            <a
                class="mt-2 inline-block text-sm hover:underline"
                href="{{ $this->assignments->listUrl }}"
                >{{ __('capell-admin::dashboard.work_first_view_work') }}</a
            >
        @endif
        @if ($this->data->total > $visibleDrafts && $this->data->listUrl !== null)
            <a
                class="mt-2 inline-block text-sm hover:underline"
                href="{{ $this->data->listUrl }}"
                >{{ __('capell-admin::dashboard.work_first_view_work') }}</a
            >
        @endif
        @if (in_array($this->pageFilters['site_id'] ?? null, [null, ''], true) && (($this->assignments->total > $visibleAssignments && $this->assignments->listUrl === null) || ($this->data->total > $visibleDrafts && $this->data->listUrl === null)))
            <p class="mt-2 text-xs text-gray-500">{{ __('capell-admin::dashboard.work_first_choose_site_queue') }}</p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
