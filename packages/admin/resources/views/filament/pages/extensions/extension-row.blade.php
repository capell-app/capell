@php
    use Illuminate\Support\Js;

    $record = is_array($record ?? null) ? $record : $getRecord();
    $label = (string) ($record['label'] ?? '');
    $packageName = (string) ($record['packageName'] ?? '');
    $displayName = $label !== '' ? $label : $packageName;
    $description = is_string($record['description'] ?? null) ? $record['description'] : null;
    $imageUrl = is_string($record['imageUrl'] ?? null) && $record['imageUrl'] !== '' ? $record['imageUrl'] : null;
    $primaryUrl = is_string($record['primaryUrl'] ?? null) && $record['primaryUrl'] !== '' ? $record['primaryUrl'] : null;
    $managementSurface = is_array($record['managementSurfaces'] ?? null) ? ($record['managementSurfaces'][0] ?? null) : null;
    $version = is_string($record['version'] ?? null) && $record['version'] !== '' ? $record['version'] : null;
    $latestVersion = is_string($record['latestVersion'] ?? null) && $record['latestVersion'] !== '' ? $record['latestVersion'] : null;
    $installed = ($record['installed'] ?? false) === true;
    $enabled = ($record['enabled'] ?? true) !== false;
    $blocked = ($record['blocked'] ?? false) === true;
    $needsAttention = ($record['needsAttention'] ?? false) === true;
    $updateAvailable = ($record['updateAvailable'] ?? false) === true;
    $attentionReasons = array_values(array_filter(
        is_array($record['attentionReasons'] ?? null) ? $record['attentionReasons'] : [],
        fn (mixed $reason): bool => is_string($reason) && $reason !== '',
    ));
    $productGroup = is_string($record['productGroup'] ?? null) && $record['productGroup'] !== ''
        ? str($record['productGroup'])->replace(['-', '_'], ' ')->headline()->toString()
        : null;

    // One status per row, most urgent first, so the list can be scanned top to
    // bottom. Updates are not a fault and get their own quieter badge.
    [$statusKey, $statusColor] = match (true) {
        ! $installed => ['not_installed', 'gray'],
        $blocked => ['blocked', 'danger'],
        $needsAttention => ['needs_attention', 'warning'],
        ! $enabled => ['disabled', 'gray'],
        default => ['active', 'success'],
    };

    $maturity = in_array($record['maturity'] ?? null, ['stable', 'beta', 'labs'], true) ? $record['maturity'] : 'labs';
    $maturityColor = match ($maturity) {
        'stable' => 'success',
        'beta' => 'warning',
        default => 'gray',
    };

    $initials = collect(preg_split('/\s+/', trim($displayName)) ?: [])
        ->filter()
        ->map(fn (string $part): string => mb_substr($part, 0, 1))
        ->take(2)
        ->implode('');
    $initials = mb_strtoupper($initials !== '' ? $initials : 'EX');
    $rowTitleId = 'extension-title-' . hash('sha256', $packageName !== '' ? $packageName : $displayName);
@endphp

<article
    class="flex min-w-0 items-start gap-3 px-1 py-1 sm:items-center"
    aria-labelledby="{{ $rowTitleId }}"
    data-extension-row="{{ $packageName }}"
    data-extension-status="{{ $statusKey }}"
>
    <div
        class="grid size-10 shrink-0 place-items-center overflow-hidden rounded-md border border-gray-200 bg-gray-50 text-sm font-semibold text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400"
        aria-hidden="true"
    >
        @if ($imageUrl !== null)
            <img
                src="{{ $imageUrl }}"
                alt=""
                class="size-full object-cover"
                loading="lazy"
            />
        @else
            {{ $initials }}
        @endif
    </div>

    <div class="min-w-0 flex-1">
        <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
            <h3
                id="{{ $rowTitleId }}"
                class="min-w-0 truncate text-sm font-semibold text-gray-950 dark:text-white"
            >
                @if (is_array($managementSurface))
                    <button
                        type="button"
                        wire:click="mountTableAction('manageExtension', {{ Js::from($packageName) }})"
                        class="hover:text-primary-600 dark:hover:text-primary-400 text-start hover:underline focus:underline focus:outline-none"
                    >
                        {{ $displayName }}
                    </button>
                @elseif ($primaryUrl !== null)
                    <a
                        href="{{ $primaryUrl }}"
                        class="hover:text-primary-600 dark:hover:text-primary-400 hover:underline focus:underline focus:outline-none"
                    >
                        {{ $displayName }}
                    </a>
                @else
                    {{ $displayName }}
                @endif
            </h3>

            <x-filament::badge
                :color="$statusColor"
                size="sm"
                data-extension-status-badge
            >
                {{ __('capell-admin::generic.extension_status_' . $statusKey) }}
            </x-filament::badge>

            @if ($maturity !== 'stable')
                <x-filament::badge
                    :color="$maturityColor"
                    size="sm"
                    data-release-status="{{ $maturity }}"
                >
                    <span class="sr-only"
                        >{{ __('capell-admin::marketplace.release_status.label') }}:</span
                    >
                    {{ __('capell-admin::marketplace.release_status.' . $maturity) }}
                </x-filament::badge>
            @endif

            @if ($updateAvailable)
                <x-filament::badge
                    color="info"
                    size="sm"
                    data-extension-update-badge
                >
                    {{ $latestVersion !== null
                        ? __('capell-admin::generic.extension_update_available_version', ['version' => $latestVersion])
                        : __('capell-admin::generic.extension_operations_tab_updates') }}
                </x-filament::badge>
            @endif
        </div>

        <p class="mt-0.5 flex min-w-0 flex-wrap gap-x-2 text-xs text-gray-500 dark:text-gray-400">
            <span class="truncate font-mono">{{ $packageName }}</span>
            <span
                >{{ $version !== null ? __('capell-admin::generic.extension_row_version', ['version' => $version]) : __('capell-admin::generic.unknown') }}</span
            >
            @if ($productGroup !== null)
                <span>{{ $productGroup }}</span>
            @endif
        </p>

        @if ($description !== null && $description !== '')
            <p class="mt-1 line-clamp-1 text-sm text-gray-600 dark:text-gray-300">{{ $description }}</p>
        @endif

        @if ($attentionReasons !== [])
            <div
                class="text-warning-800 dark:text-warning-200 mt-1.5 text-sm"
                data-extension-attention
            >
                <p class="sr-only">{{ __('capell-admin::generic.extension_operations_tab_needs_attention') }}</p>
                <ul class="space-y-0.5">
                    @foreach ($attentionReasons as $attentionReason)
                        <li class="flex gap-1.5">
                            @svg('heroicon-m-exclamation-triangle', 'mt-0.5 size-4 shrink-0')
                            <span>{{ $attentionReason }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</article>
