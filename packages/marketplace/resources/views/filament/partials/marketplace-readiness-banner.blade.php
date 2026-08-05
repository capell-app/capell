@php
    /** @var \Capell\Marketplace\Data\MarketplaceEnvironmentReadinessData $readiness */
    use Capell\Marketplace\Enums\MarketplaceInstallCapability;

    $reportedChecks = [...$readiness->failedChecks(), ...$readiness->warnedChecks()];
@endphp

@if ($readiness->capability !== MarketplaceInstallCapability::Automated)
    <div
        data-capell-marketplace-readiness-banner
        data-capell-marketplace-readiness-capability="{{ $readiness->capability->value }}"
        role="status"
        aria-live="polite"
        @class([
            'space-y-2 rounded-lg px-4 py-3 text-sm ring-1',
            'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30' => $readiness->isBlocked(),
            'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/30' => ! $readiness->isBlocked(),
        ])
    >
        <p class="font-semibold">{{ $readiness->capability->getLabel() }}</p>

        <p>
            {{ __('capell-marketplace::marketplace.readiness.banner.' . $readiness->capability->value) }}
        </p>

        @foreach ($reportedChecks as $check)
            <p data-capell-marketplace-readiness-check="{{ $check->key }}">
                {{ $check->message }}

                @if ($check->remediation !== null)
                    {{ $check->remediation }}
                @endif
            </p>
        @endforeach
    </div>
@endif
