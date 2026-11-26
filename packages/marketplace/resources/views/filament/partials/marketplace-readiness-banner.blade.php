@php
    /** @var \Capell\Marketplace\Data\MarketplaceEnvironmentReadinessData $readiness */
    use Capell\Marketplace\Enums\MarketplaceInstallCapability;

    $reportedChecks = [
        ...array_map(fn ($check): array => ['check' => $check, 'failed' => true], $readiness->failedChecks()),
        ...array_map(fn ($check): array => ['check' => $check, 'failed' => false], $readiness->warnedChecks()),
    ];
    $blocked = $readiness->isBlocked();
@endphp

@if ($readiness->capability !== MarketplaceInstallCapability::Automated)
    <div
        data-capell-marketplace-readiness-banner
        data-capell-marketplace-readiness-capability="{{ $readiness->capability->value }}"
        role="status"
        aria-live="polite"
        @class([
            'overflow-hidden rounded-xl text-sm ring-1',
            'bg-danger-50 ring-danger-600/20 dark:bg-danger-500/10 dark:ring-danger-400/20' => $blocked,
            'bg-warning-50 ring-warning-600/20 dark:bg-warning-500/10 dark:ring-warning-400/20' => ! $blocked,
        ])
    >
        <div class="flex items-start gap-3 px-4 py-3">
            <x-filament::icon
                :icon="$blocked ? 'heroicon-o-no-symbol' : 'heroicon-o-exclamation-triangle'"
                @class([
                    'mt-0.5 size-5 shrink-0',
                    'text-danger-600 dark:text-danger-400' => $blocked,
                    'text-warning-600 dark:text-warning-400' => ! $blocked,
                ])
            />
            <div class="min-w-0 space-y-0.5">
                <p
                    @class([
                    'font-semibold',
                    'text-danger-900 dark:text-danger-100' => $blocked,
                    'text-warning-900 dark:text-warning-100' => ! $blocked,
                ])
                >
                    {{ $readiness->capability->getLabel() }}
                </p>
                <p
                    @class([
                    'text-danger-800 dark:text-danger-200' => $blocked,
                    'text-warning-800 dark:text-warning-200' => ! $blocked,
                ])
                >
                    {{ __('capell-marketplace::marketplace.readiness.banner.' . $readiness->capability->value) }}
                </p>
            </div>
        </div>

        @if ($reportedChecks !== [])
            <ul
                class="divide-y divide-gray-950/5 border-t border-gray-950/5 bg-white/70 dark:divide-white/10 dark:border-white/10 dark:bg-gray-950/30"
            >
                @foreach ($reportedChecks as $reported)
                    @php($check = $reported['check'])
                    <li
                        class="flex gap-3 px-4 py-3"
                        data-capell-marketplace-readiness-check="{{ $check->key }}"
                    >
                        <x-filament::icon
                            :icon="$reported['failed'] ? 'heroicon-m-x-circle' : 'heroicon-m-exclamation-circle'"
                            @class([
                                'mt-0.5 size-4 shrink-0',
                                'text-danger-500' => $reported['failed'],
                                'text-warning-500' => ! $reported['failed'],
                            ])
                        />
                        <div class="min-w-0 space-y-1">
                            <p class="text-gray-950 dark:text-white">{{ $check->message }}</p>
                            @if ($check->remediation !== null)
                                <p class="break-words text-gray-600 dark:text-gray-300">{{ $check->remediation }}</p>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
