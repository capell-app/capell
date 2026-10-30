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
        @include('capell-admin::livewire.partials.publish-status-rows', ['view' => $view])

        @if ($readiness !== null)
            <dl
                class="space-y-2 border-t border-gray-100 pt-2.5 dark:border-white/10"
            >
                <div class="flex items-start justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">
                        {{ __('capell-admin::publish_panel.readiness_summary') }}
                    </dt>
                    <dd
                        class="text-right font-medium text-gray-950 dark:text-white"
                    >
                        {{
                            $readiness->blockingCheckIds === []
                            ? __('capell-admin::publish_panel.readiness_checks_passed')
                            : __('capell-admin::publish_panel.readiness_checks_incomplete')
                        }}
                    </dd>
                </div>

                @if ($readiness->blockingCheckIds !== [])
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">
                            {{ __('capell-admin::reports.publishing_readiness_blockers') }}
                        </dt>
                        <dd
                            class="text-danger-600 dark:text-danger-400 text-right font-medium"
                        >
                            {{ trans_choice('capell-admin::reports.publishing_readiness_blocker_count', count($readiness->blockingCheckIds), ['count' => count($readiness->blockingCheckIds)]) }}
                        </dd>
                    </div>
                    <div class="space-y-2">
                        <dt class="sr-only">
                            {{ __('capell-admin::reports.publishing_readiness_blockers') }}
                        </dt>
                        <dd>
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
                        </dd>
                    </div>
                @endif
            </dl>
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
