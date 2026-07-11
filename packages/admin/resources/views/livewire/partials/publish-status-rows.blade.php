@php
    use Capell\Admin\Data\Pages\PublishPanelViewData;
    use Filament\Support\Enums\IconSize;
    use Filament\Support\Icons\Heroicon;

    /** @var PublishPanelViewData $view */
    $displayFormat = 'j M Y, H:i';
    $titleFormat = 'l j F Y, H:i';
    $iconClass = 'h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500';
    $labelClass = 'text-gray-500 dark:text-gray-400';
    $valueClass = 'ml-auto font-medium text-gray-800 dark:text-gray-200';
@endphp

{{-- Status (Active / Inactive) — only for Statusable records --}}
@if ($view->isStatusable())
    <div class="flex items-center gap-2">
        @svg(Heroicon::OutlinedBolt->getIconForSize(IconSize::Small), $iconClass, ['aria-hidden' => 'true'])
        <span class="{{ $labelClass }}">
            {{ __('capell-admin::publish_panel.status') }}
        </span>
        <span class="ml-auto inline-flex items-center gap-2">
            <span
                @class([
                    'inline-flex items-center gap-1.5 font-medium',
                    'text-success-600 dark:text-success-400' => $view->isEnabled(),
                    'text-gray-400 dark:text-gray-500' => ! $view->isEnabled(),
                ])
            >
                <span
                    @class([
                        'h-1.5 w-1.5 rounded-full',
                        'bg-success-500' => $view->isEnabled(),
                        'bg-gray-300 dark:bg-gray-600' => ! $view->isEnabled(),
                    ])
                ></span>
                {{ $view->isEnabled() ? __('capell-admin::publish_panel.status_active') : __('capell-admin::publish_panel.status_inactive') }}
            </span>
            @if ($this->canManage())
                <span class="-my-1">{{ $this->toggleStatusAction }}</span>
            @endif
        </span>
    </div>
@endif

@if ($view->isPublishable())
    {{-- Published --}}
    <div class="flex items-center gap-2">
        @svg(Heroicon::OutlinedCheckCircle->getIconForSize(IconSize::Small), $iconClass, ['aria-hidden' => 'true'])
        <span class="{{ $labelClass }}">
            {{ __('capell-admin::publish_panel.published_label') }}
        </span>
        @if ($view->publishedAt !== null)
            <span
                class="{{ $valueClass }}"
                title="{{ $view->publishedAt->translatedFormat($titleFormat) }}"
            >
                {{ $view->publishedAt->translatedFormat($displayFormat) }}
            </span>
        @else
            <span class="ml-auto text-gray-400 dark:text-gray-500">
                {{ __('capell-admin::publish_panel.not_published') }}
            </span>
        @endif
    </div>

    {{-- Goes live / schedule reveal (hidden once live or expired) --}}
    @if (! $view->isLive() && ! $view->isExpired())
        <div class="flex items-center gap-2">
            @svg(Heroicon::OutlinedClock->getIconForSize(IconSize::Small), $iconClass, ['aria-hidden' => 'true'])
            <span class="{{ $labelClass }}">
                {{ __('capell-admin::publish_panel.goes_live') }}
            </span>
            <span class="ml-auto inline-flex items-center gap-2">
                @if ($view->isScheduled() && $view->goesLiveAt !== null)
                    <span
                        class="font-medium text-amber-600 dark:text-amber-400"
                        title="{{ $view->goesLiveAt->translatedFormat($titleFormat) }}"
                    >
                        {{ $view->goesLiveAt->diffForHumans() }}
                    </span>
                @endif

                @if ($this->canManage())
                    <span class="-my-1">
                        {{ $this->schedulePublishAction }}
                    </span>
                @endif
            </span>
        </div>
    @endif

    {{-- Expires (only meaningful while live or scheduled) --}}
    @if ($view->isLive() || $view->isScheduled())
        <div class="flex items-center gap-2">
            @svg(Heroicon::OutlinedCalendarDays->getIconForSize(IconSize::Small), $iconClass, ['aria-hidden' => 'true'])
            <span class="{{ $labelClass }}">
                {{ __('capell-admin::publish_panel.expires') }}
            </span>
            <span class="ml-auto inline-flex items-center gap-2">
                @if ($view->hasScheduledUnpublish() && $view->expiresAt !== null)
                    <span
                        class="font-medium text-gray-800 dark:text-gray-200"
                        title="{{ $view->expiresAt->translatedFormat($titleFormat) }}"
                    >
                        {{ $view->expiresAt->translatedFormat($displayFormat) }}
                    </span>
                @else
                    <span class="text-gray-400 dark:text-gray-500">
                        {{ __('capell-admin::publish_panel.never') }}
                    </span>
                @endif
                @if ($this->canManage())
                    <span class="-my-1">{{ $this->setExpiryAction }}</span>
                @endif
            </span>
        </div>
    @endif

    {{-- Expired notice --}}
    @if ($view->isExpired() && $view->expiresAt !== null)
        <div class="flex items-center gap-2">
            @svg(Heroicon::OutlinedExclamationTriangle->getIconForSize(IconSize::Small), 'text-danger-500 dark:text-danger-400 h-4 w-4 shrink-0', ['aria-hidden' => 'true'])
            <span class="{{ $labelClass }}">
                {{ __('capell-admin::publish_panel.unpublished_on') }}
            </span>
            <span
                class="{{ $valueClass }}"
                title="{{ $view->expiresAt->translatedFormat($titleFormat) }}"
            >
                {{ $view->expiresAt->translatedFormat($displayFormat) }}
            </span>
        </div>
    @endif
@endif
