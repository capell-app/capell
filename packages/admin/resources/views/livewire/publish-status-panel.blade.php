@php
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;

    $view = $this->viewData;
    $readiness = $view->isPublishable() ? $this->readiness : null;
    $status = $view->status;
    $titleFormat = 'l j F Y, H:i';
@endphp

<div
    class="capell-publish-status-panel overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
    aria-labelledby="publish-status-panel-title"
>
    {{-- Header: title + status pill --}}
    <div class="flex items-center justify-between gap-3 px-4 py-3">
        <h3
            id="publish-status-panel-title"
            class="text-sm font-semibold text-gray-950 dark:text-white"
        >
            {{ __('capell-admin::publish_panel.title') }}
        </h3>

        @if ($view->isPublishable())
            <x-filament::badge
                :color="$status->getColor()"
                :icon="$status->getIcon()"
            >
                {{ $status->getLabel() }}
            </x-filament::badge>
        @else
            <x-filament::badge
                :color="$view->isEnabled() ? 'success' : 'gray'"
                :icon="$view->isEnabled() ? 'heroicon-m-check-circle' : 'heroicon-m-pause-circle'"
            >
                {{ $view->isEnabled() ? __('capell-admin::publish_panel.status_active') : __('capell-admin::publish_panel.status_inactive') }}
            </x-filament::badge>
        @endif
    </div>

    {{-- Summary rows --}}
    <div
        class="space-y-2.5 border-t border-gray-100 px-4 py-3 text-sm dark:border-white/10"
    >
        @if ($view->isPublishable())
            <p
                class="text-gray-950 dark:text-white"
                data-publish-summary
            >
                @if ($view->isLive())
                    {{
                        $view->publishedAt !== null
                        ? __('capell-admin::publish_panel.summary_live_since', ['date' => $view->publishedAt->translatedFormat('j M Y')])
                        : __('capell-admin::publish_panel.summary_live')
                    }}
                    {{
                        $view->hasScheduledUnpublish() && $view->expiresAt !== null
                        ? __('capell-admin::publish_panel.summary_unpublishes_on', ['date' => $view->expiresAt->translatedFormat('j M Y')])
                        : __('capell-admin::publish_panel.summary_stays_live')
                    }}
                @elseif ($view->isScheduled() && $view->goesLiveAt !== null)
                    {{ __('capell-admin::publish_panel.summary_scheduled', ['date' => $view->goesLiveAt->translatedFormat('j M Y, H:i')]) }}
                @else
                    {{ __('capell-admin::publish_panel.summary_not_live') }}
                @endif
            </p>
        @endif

        @include('capell-admin::livewire.partials.publish-status-rows', ['view' => $view])

        @if ($readiness !== null)
            <details
                class="group border-t border-gray-100 pt-2.5 dark:border-white/10"
                data-publish-checks
                @if ($readiness->blockingCheckIds !== []) open @endif
            >
                <summary
                    class="flex cursor-pointer list-none items-center gap-2 font-medium [&::-webkit-details-marker]:hidden"
                >
                    @if ($readiness->blockingCheckIds === [])
                        @svg(Heroicon::CheckCircle->getIconForSize(IconSize::Small), 'text-success-600 dark:text-success-400 h-4 w-4 shrink-0', ['aria-hidden' => 'true'])
                        <span class="text-gray-950 dark:text-white">
                            {{ __('capell-admin::publish_panel.readiness_ready') }}
                        </span>
                    @else
                        @svg(Heroicon::ExclamationTriangle->getIconForSize(IconSize::Small), 'text-danger-600 dark:text-danger-400 h-4 w-4 shrink-0', ['aria-hidden' => 'true'])
                        <span class="text-danger-600 dark:text-danger-400">
                            {{ trans_choice('capell-admin::publish_panel.readiness_to_fix', count($readiness->blockingCheckIds), ['count' => count($readiness->blockingCheckIds)]) }}
                        </span>
                    @endif
                    @svg(Heroicon::ChevronDown->getIconForSize(IconSize::Small), 'ml-auto h-4 w-4 shrink-0 text-gray-400 transition group-open:rotate-180', ['aria-hidden' => 'true'])
                </summary>

                <div class="mt-2 text-gray-700 dark:text-gray-300">
                    @if ($readiness->blockingCheckIds === [])
                        <ul class="space-y-1.5">
                            <li class="flex items-center gap-1.5">
                                @svg(Heroicon::Check->getIconForSize(IconSize::Small), 'text-success-600 dark:text-success-400 h-4 w-4 shrink-0', ['aria-hidden' => 'true'])
                                {{ __('capell-admin::publish_panel.check_page_type') }}
                            </li>
                            <li class="flex items-center gap-1.5">
                                @svg(Heroicon::Check->getIconForSize(IconSize::Small), 'text-success-600 dark:text-success-400 h-4 w-4 shrink-0', ['aria-hidden' => 'true'])
                                {{ __('capell-admin::publish_panel.check_layout') }}
                            </li>
                            <li class="flex items-center gap-1.5">
                                @svg(Heroicon::Check->getIconForSize(IconSize::Small), 'text-success-600 dark:text-success-400 h-4 w-4 shrink-0', ['aria-hidden' => 'true'])
                                {{ __('capell-admin::publish_panel.check_translation') }}
                            </li>
                            <li class="flex items-center gap-1.5">
                                @svg(Heroicon::Check->getIconForSize(IconSize::Small), 'text-success-600 dark:text-success-400 h-4 w-4 shrink-0', ['aria-hidden' => 'true'])
                                {{ __('capell-admin::publish_panel.check_address') }}
                            </li>
                            <li class="text-gray-500 dark:text-gray-400">
                                {{ __('capell-admin::publish_panel.check_live_not_verified') }}
                            </li>
                        </ul>
                    @else
                        <ul
                            class="list-disc space-y-2 pl-5 text-gray-700 dark:text-gray-300"
                        >
                            @foreach ($readiness->blockingCheckIds as $checkId)
                                <li>
                                    {{ match ($checkId) {
                                                'publishing.blueprint.missing' => __('capell-admin::publish_panel.blocker_blueprint_missing'),
                                                'publishing.layout.missing' => __('capell-admin::publish_panel.blocker_layout_missing'),
                                                'publishing.translation.missing' => __('capell-admin::publish_panel.blocker_translation_missing'),
                                                'publishing.url.active-missing' => __('capell-admin::publish_panel.blocker_url_active_missing'),
                                                default => __('capell-admin::publish_panel.blocker_unknown'),
                                            } }}
                                    @if ($checkId === 'publishing.url.active-missing')
                                        <span
                                            class="block text-xs"
                                            >{{ $this->pageUrlRecoveryInstruction() }}</span
                                        >
                                    @elseif ($this->canManage())
                                        <span
                                            class="block text-xs"
                                            >{{ match ($checkId) {
                                                        'publishing.blueprint.missing' => __('capell-admin::publish_panel.blocker_next_blueprint_missing'),
                                                        'publishing.layout.missing' => __('capell-admin::publish_panel.blocker_next_layout_missing'),
                                                        'publishing.translation.missing' => __('capell-admin::publish_panel.blocker_next_translation_missing'),
                                                        default => __('capell-admin::publish_panel.blocker_ask_editor'),
                                                    } }}</span
                                        >
                                    @else
                                        <span
                                            class="block text-xs"
                                            >{{ __('capell-admin::publish_panel.blocker_ask_editor') }}</span
                                        >
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </details>
        @endif
    </div>

    {{-- Extension slots injected by PublishPanelExtender implementations --}}
    @foreach ($this->extensions as $extension)
        <div class="border-t border-gray-100 px-4 py-3 dark:border-white/10">
            {!! $extension !!}
        </div>
    @endforeach

    {{-- Footer action bar: compact dropdown for narrow sidebar panels --}}
    @if ($this->canManage() && $view->isPublishable())
        <div
            class="border-t border-gray-100 bg-gray-50/80 px-4 py-3 dark:border-white/10 dark:bg-white/5"
        >
            @if ($this->publishNowAction->isVisible()
                 || $this->unpublishAction->isVisible()
                 || $this->revertToDraftAction->isVisible()
                 || $this->cancelScheduledUnpublishAction->isVisible())
                <div
                    class="w-full [&_.fi-btn]:w-full [&_.fi-btn]:justify-center"
                >
                    <x-filament-actions::group
                        :actions="
                            [
                                $this->publishNowAction,
                                $this->unpublishAction,
                                $this->revertToDraftAction,
                                $this->cancelScheduledUnpublishAction,
                            ]
                        "
                        :label="__('capell-admin::publish_panel.publishing_actions')"
                        :tooltip="__('capell-admin::publish_panel.publishing_actions')"
                        :button="true"
                        icon="heroicon-m-chevron-down"
                        color="gray"
                        dropdown-placement="top-end"
                    />
                </div>
            @endif
        </div>
    @endif

    {{-- Metadata strip: light, icon-led, exact datetimes on hover --}}
    @if ($view->updatedAt !== null || $view->createdAt !== null)
        <div
            class="flex flex-col gap-1 border-t border-gray-100 px-4 py-2.5 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400"
        >
            @if ($view->updatedAt !== null)
                <span
                    class="inline-flex items-center gap-1.5"
                    title="{{ $view->updatedAt->translatedFormat($titleFormat) }}"
                >
                    @svg(Heroicon::OutlinedPencil->getIconForSize(IconSize::Small), 'h-3.5 w-3.5 shrink-0', ['aria-hidden' => 'true'])
                    <span class="truncate">
                        @if ($view->editorName !== null)
                            {{ $view->editorName }} ·
                        @endif

                        {{ __('capell-admin::publish_panel.updated') }} {{ $view->updatedAt->diffForHumans() }}
                    </span>
                </span>
            @endif

            @if ($view->createdAt !== null)
                <span
                    class="inline-flex items-center gap-1.5"
                    title="{{ $view->createdAt->translatedFormat($titleFormat) }}"
                >
                    @svg(Heroicon::OutlinedPlus->getIconForSize(IconSize::Small), 'h-3.5 w-3.5 shrink-0', ['aria-hidden' => 'true'])
                    <span class="truncate">
                        {{ __('capell-admin::publish_panel.created') }} {{ $view->createdAt->diffForHumans() }}
                        @if ($view->creatorName !== null)
                            · {{ $view->creatorName }}
                        @endif
                    </span>
                </span>
            @endif
        </div>
    @endif

    <x-filament-actions::modals />
</div>
