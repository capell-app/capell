@php($approvals = $this->data)
<x-filament-widgets::widget
    wire:poll.60s
    :hidden="! ($approvals->available && $approvals->canReview)"
>
    @if ($approvals->available && $approvals->canReview)
        <x-filament::section
            :heading="__('capell-admin::dashboard.work_first_approvals_heading')"
        >
            @if ($approvals->assignedReviewCount > 0)
                <p class="text-xs text-gray-600 dark:text-gray-400">{{ __('capell-admin::dashboard.work_first_approvals_total', ['count' => $approvals->assignedReviewCount]) }}</p>
            @endif
            @if ($this->pendingApprovals > 0)
                <p class="mb-2 text-sm">
                    {{ __('capell-admin::dashboard.work_first_new_items', ['count' => $this->pendingApprovals]) }}
                    <button
                        type="button"
                        class="font-medium underline"
                        wire:click="applyApprovalUpdates"
                    >
                        {{ __('capell-admin::dashboard.work_first_apply_updates') }}
                    </button>
                </p>
            @endif
            @forelse ($this->approvalRows as $item)
                <div class="py-2 text-sm">
                    <a
                        href="{{ $item->reviewUrl }}"
                        class="font-medium hover:underline"
                        >{{ $item->title }}</a
                    >
                    <p class="text-gray-600 dark:text-gray-400">{{ $item->siteName }} · {{ $item->submitter }}</p>
                    @if ($item->submittedAt !== null)
                        <p class="text-xs text-gray-600 dark:text-gray-400">
                            {{ __('capell-admin::dashboard.work_first_submitted') }}
                            <time
                                datetime="{{ $item->submittedAt }}"
                                title="{{ $item->submittedAt }}"
                                >{{ \Carbon\CarbonImmutable::parse($item->submittedAt)->diffForHumans() }}</time
                            >
                        </p>
                    @endif
                    @if ($item->deadline !== null)
                        <p class="text-xs text-gray-600 dark:text-gray-400">
                            {{ __('capell-admin::dashboard.work_first_deadline') }}
                            <time
                                datetime="{{ $item->deadline }}"
                                title="{{ $item->deadline }}"
                                >{{ \Carbon\CarbonImmutable::parse($item->deadline)->diffForHumans() }}</time
                            >
                            · {{ $item->timezone ?? __('capell-admin::dashboard.work_first_utc_offset', ['offset' => \Carbon\CarbonImmutable::parse($item->deadline)->format('P')]) }}
                        </p>
                    @endif
                </div>
            @empty
                @if ($this->pendingApprovals === 0)
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        {{ $approvals->hasReviewed ? __('capell-admin::dashboard.work_first_approvals_caught_up') : __('capell-admin::dashboard.work_first_approvals_intro') }}
                    </p>
                @endif
            @endforelse
            @if ($approvals->assignedReviewCount > count($this->approvalRows) && $approvals->assignedReviewUrl !== null)
                <a
                    href="{{ $approvals->assignedReviewUrl }}"
                    class="mt-2 inline-block text-sm hover:underline"
                >
                    {{ __('capell-admin::dashboard.work_first_view_assigned', ['count' => $approvals->assignedReviewCount]) }}
                </a>
            @endif
            @if ($approvals->teamReviewCount > 0 && $approvals->teamReviewUrl !== null)
                <a
                    href="{{ $approvals->teamReviewUrl }}"
                    class="mt-2 inline-block text-sm hover:underline"
                >
                    {{ __('capell-admin::dashboard.work_first_team_reviews', ['count' => $approvals->teamReviewCount]) }}
                </a>
            @endif
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
