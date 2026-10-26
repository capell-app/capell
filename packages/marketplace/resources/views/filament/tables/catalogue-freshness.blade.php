@if ($freshness->stale)
    <div
        class="rounded-lg border border-amber-300 p-3 text-sm"
        data-capell-catalogue-stale
    >
        @if ($freshness->refreshFailed)
            <p data-capell-catalogue-refresh-failed>{{ __('capell-marketplace::marketplace.catalogue_freshness.failed') }}</p>
        @else
            <p>{{ __('capell-marketplace::marketplace.catalogue_freshness.retained') }}</p>
        @endif
        <p>{{ $freshness->observedAt ? __('capell-marketplace::marketplace.catalogue_freshness.observed', ['date' => $freshness->observedAt]) : __('capell-marketplace::marketplace.catalogue_freshness.unknown_date') }}</p>
        <button
            type="button"
            wire:click="$refresh"
            wire:loading.attr="disabled"
        >
            {{ __('capell-marketplace::marketplace.catalogue_freshness.recheck') }}
        </button>
    </div>
@endif
