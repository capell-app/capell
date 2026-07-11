@php
    use Carbon\CarbonInterface;

    $packageName = $record['packageName'] ?? '';
    $description = $record['description'] ?? null;
    $version = $record['version'] ?? null;
    $latestVersion = $record['latestVersion'] ?? null;
    $authorName = $record['authorName'] ?? null;
    $tier = $record['tier'] ?? 'free';
    $certification = $record['certification'] ?? 'community';
    $runtimeStatus = $record['runtimeStatus'] ?? 'not_installed';
    $healthState = $record['healthState'] ?? 'ok';
    $updateAvailable = ($record['updateAvailable'] ?? false) === true;
    $core = ($record['core'] ?? false) === true;
    $riskScore = (int) ($record['riskScore'] ?? 0);
    $installedAt = $record['installedAt'] ?? null;
    $documentationUrl = $record['documentationUrl'] ?? null;
    $formatState = fn (?string $state): string => str($state ?? '')->replace(['-', '_'], ' ')->headline()->toString();
    $formatCompactState = fn (?string $state): string => ucfirst(str_replace('_', ' ', $state ?? ''));

    $details = [
        __('capell-admin::table.package') => $packageName,
        __('capell-admin::table.version') => $version,
        __('capell-admin::table.author') => $authorName,
        __('capell-admin::table.tier') => $formatState($tier),
        __('capell-admin::table.certification') => $formatCompactState($certification),
        __('capell-admin::table.runtime_status') => $formatState($runtimeStatus),
        __('capell-admin::table.health_state') => $formatState($healthState),
        __('capell-admin::generic.extension_risk_score', ['score' => $riskScore]) => $core ? __('capell-admin::generic.core_package') : __('capell-admin::generic.optional_addon'),
    ];
@endphp

<div class="space-y-6">
    @if (is_string($description) && $description !== '')
        <p class="text-sm leading-6 text-gray-600 dark:text-gray-300">
            {{ $description }}
        </p>
    @endif

    <dl class="grid gap-3 sm:grid-cols-2">
        @foreach ($details as $detailLabel => $detailValue)
            @if ($detailValue !== null && $detailValue !== '')
                <div
                    class="rounded-lg border border-gray-200 bg-gray-50/70 p-3 dark:border-white/10 dark:bg-white/5"
                >
                    <dt
                        class="text-xs font-medium text-gray-500 dark:text-gray-400"
                    >
                        {{ $detailLabel }}
                    </dt>
                    <dd
                        class="mt-1 text-sm font-semibold text-gray-950 dark:text-white"
                    >
                        {{ $detailValue }}
                    </dd>
                </div>
            @endif
        @endforeach

        @if ($updateAvailable && is_string($latestVersion) && $latestVersion !== '')
            <div
                class="border-warning-200 bg-warning-50 dark:border-warning-400/20 dark:bg-warning-400/10 rounded-lg border p-3"
            >
                <dt
                    class="text-warning-700 dark:text-warning-200 text-xs font-medium"
                >
                    {{ __('capell-admin::table.latest_version') }}
                </dt>
                <dd
                    class="text-warning-800 dark:text-warning-100 mt-1 text-sm font-semibold"
                >
                    {{ __('capell-admin::generic.extension_version_available', ['version' => $latestVersion]) }}
                </dd>
            </div>
        @endif

        <div
            class="rounded-lg border border-gray-200 bg-gray-50/70 p-3 dark:border-white/10 dark:bg-white/5"
        >
            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('capell-admin::table.installed_at') }}
            </dt>
            <dd
                class="mt-1 text-sm font-semibold text-gray-950 dark:text-white"
            >
                @if ($installedAt instanceof CarbonInterface)
                    {{ $installedAt->toFormattedDateString() }}
                @else
                    {{ __('capell-admin::table.not_installed') }}
                @endif
            </dd>
        </div>
    </dl>

    @if (is_string($documentationUrl) && $documentationUrl !== '')
        <div>
            <a
                href="{{ $documentationUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 inline-flex items-center gap-1.5 text-sm font-medium"
            >
                <x-filament::icon
                    icon="heroicon-m-book-open"
                    class="h-4 w-4"
                />
                {{ __('capell-admin::generic.documentation') }}
            </a>
        </div>
    @endif
</div>
