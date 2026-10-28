<li class="flex items-start gap-3 py-3">
    <x-filament::icon
        icon="heroicon-o-calendar-days"
        class="mt-0.5 h-4 w-4 shrink-0 text-gray-400"
    />
    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium">{{ $item->title }} <span class="font-normal text-gray-500">· {{ $item->siteName }}</span></p>
        <p class="mt-1 text-xs text-gray-500">
            <time
                datetime="{{ $item->scheduledAt }}"
                >{{ \Carbon\CarbonImmutable::parse($item->scheduledAt)->setTimezone($item->timezone)->format('j M Y, H:i') }}</time
            >
            · {{ $item->timezone }}
        </p>
        <div class="mt-2 flex flex-wrap items-center gap-2">
            <x-filament::badge
                :color="$item->readiness === 'blocked' ? 'warning' : 'gray'"
                >{{ __('capell-admin::work-first.' . $item->readiness) }}</x-filament::badge
            >
            @if ($item->readiness === 'blocked')
                @if ($attentionVisible)
                    <a
                        href="#work-first-attention"
                        class="text-xs text-primary-600 hover:underline dark:text-primary-400"
                        >{{ __('capell-admin::work-first.see_attention') }}</a
                    >
                @else
                    <span
                        class="text-xs text-gray-500"
                        >{{ implode(' · ', array_map(fn ($reason) => __('capell-admin::work-first.reason_' . $reason), $item->reasons)) }}</span
                    >
                    @if ($item->url)
                        <a
                            href="{{ $item->url }}"
                            class="text-xs text-primary-600 hover:underline dark:text-primary-400"
                            >{{ __('capell-admin::work-first.review') }}</a
                        >
                    @endif
                @endif
            @elseif ($item->url)
                <a
                    href="{{ $item->url }}"
                    class="text-xs text-primary-600 hover:underline dark:text-primary-400"
                    >{{ __('capell-admin::work-first.review') }}</a
                >
            @endif
        </div>
    </div>
</li>
