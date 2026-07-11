@php
    use Illuminate\Support\Js;
    use Illuminate\View\ComponentAttributeBag;

    $record = is_array($record ?? null) ? $record : $getRecord();
    $livewire = $livewire ?? (isset($getLivewire) ? $getLivewire() : null);
    $attributeBag = $attributes ?? new ComponentAttributeBag;
    $extraAttributes ??= [];
    $extraAttributes = is_array($extraAttributes) ? $extraAttributes : [];
    $isMarketplaceRecord = array_key_exists('composer_name', $record)
        || array_key_exists('image_url', $record)
        || array_key_exists('price_label', $record)
        || array_key_exists('rating_average', $record);
    $formatState = fn (?string $state): string => str($state ?? '')->replace(['-', '_'], ' ')->headline()->toString();

    if ($isMarketplaceRecord) {
        $label = (string) ($record['name'] ?? $record['label'] ?? '');
        $packageName = (string) ($record['composer_name'] ?? $record['packageName'] ?? '');
        $description = $record['description'] ?? __('capell-admin::marketplace.card.no_description');
        $imageUrl = $record['image_url'] ?? $record['imageUrl'] ?? null;
        $imageUrls = collect(is_array($record['image_urls'] ?? null) ? $record['image_urls'] : ($record['imageUrls'] ?? []));
        $primaryUrl = null;
        $externalUrl = null;
        $authorName = is_string($record['author_name'] ?? null) ? $record['author_name'] : null;
        $authorFilter = is_string($record['author_filter'] ?? null) ? $record['author_filter'] : $authorName;
        $version = $record['installed_version'] ?? $record['latest_version'] ?? null;
        $latestVersion = null;
        $productGroup = $record['product_group'] ?? $record['kind'] ?? null;
        $tier = $record['product_tier'] ?? 'free';
        $certification = $record['effective_certification'] ?? 'community';
        $healthState = 'ok';
        $blocked = ($record['marketplace_install_state'] ?? null) === 'blocked' || ($record['marketplace_install_state'] ?? null) === 'incompatible';
        $showBlockedTag = false;
        $updateAvailable = ($record['has_update_available'] ?? false) === true;
        $installInProgress = ($record['install_in_progress'] ?? false) === true;
        $managementSurface = null;
        $isFreeTheme = ($record['kind'] ?? null) === 'theme'
            && ($record['product_tier'] ?? null) === 'free';
        $showPrice = ! $isFreeTheme;
        $priceLabel = $record['price_label'] ?? __('capell-admin::marketplace.install.free');
        $composerName = $packageName;
        $cardTitleId = 'marketplace-extension-title-' . hash('sha256', (string) ($record['key'] ?? $label));
        $selectionInputId = 'marketplace-extension-selection-' . hash('sha256', $composerName !== '' ? $composerName : (string) ($record['key'] ?? $label));
        $selectionState = is_object($livewire) && method_exists($livewire, 'marketplaceSelectionState')
            ? $livewire->marketplaceSelectionState($record)
            : ['selectable' => false, 'selected' => false, 'dependency' => false, 'reason' => null];
        $ratingAverage = $record['rating_average'] ?? null;
        $ratingAverageLabel = is_string($record['rating_average_label'] ?? null)
            ? $record['rating_average_label']
            : __('capell-admin::marketplace.card.no_rating');
        $ratingsCountLabel = is_string($record['ratings_count_label'] ?? null)
            ? $record['ratings_count_label']
            : __('capell-admin::marketplace.card.no_ratings');
        $ratingAriaLabel = $ratingAverage === null
            ? $ratingsCountLabel
            : __('capell-admin::marketplace.card.rating_aria', ['rating' => $ratingAverageLabel, 'count' => $ratingsCountLabel]);
        $ratingStars = is_array($record['rating_stars'] ?? null) && $record['rating_stars'] !== []
            ? $record['rating_stars']
            : ['empty', 'empty', 'empty', 'empty', 'empty'];
        $stateLabels = array_values(array_filter([
            $tier,
            $certification,
            $record['support_policy'] ?? null,
            ...(is_array($record['surface_labels'] ?? null) ? $record['surface_labels'] : []),
        ]));
        $categoryLabels = is_array($record['category_labels'] ?? null) ? $record['category_labels'] : [];
        $capabilityLabels = is_array($record['capability_labels'] ?? null) ? $record['capability_labels'] : [];
        $allTags = [...$stateLabels, ...$categoryLabels, ...$capabilityLabels];
        $visibleTags = array_slice($allTags, 0, 5);
        $hiddenTags = array_slice($allTags, count($visibleTags));
    } else {
        $label = (string) ($record['label'] ?? '');
        $packageName = (string) ($record['packageName'] ?? '');
        $description = $record['description'] ?? null;
        $imageUrl = $record['imageUrl'] ?? null;
        $imageUrls = collect(is_array($record['imageUrls'] ?? null) ? $record['imageUrls'] : []);
        $primaryUrl = $record['primaryUrl'] ?? null;
        $externalUrl = $record['externalUrl'] ?? null;
        $authorName = $record['authorName'] ?? null;
        $authorFilter = null;
        $version = $record['version'] ?? null;
        $latestVersion = $record['latestVersion'] ?? null;
        $productGroup = $record['productGroup'] ?? null;
        $tier = $record['tier'] ?? 'free';
        $certification = $record['certification'] ?? 'community';
        $healthState = $record['healthState'] ?? 'ok';
        $blocked = ($record['blocked'] ?? false) === true;
        $showBlockedTag = $blocked;
        $updateAvailable = ($record['updateAvailable'] ?? false) === true;
        $installInProgress = false;
        $managementSurface = is_array($record['managementSurfaces'] ?? null) ? ($record['managementSurfaces'][0] ?? null) : null;
        $showPrice = false;
        $priceLabel = null;
        $cardTitleId = 'extension-title-' . hash('sha256', $packageName !== '' ? $packageName : $label);
        $selectionInputId = null;
        $selectionState = ['selectable' => false, 'selected' => false, 'dependency' => false, 'reason' => null];
        $ratingAverage = null;
        $ratingAverageLabel = null;
        $ratingsCountLabel = null;
        $ratingAriaLabel = null;
        $ratingStars = [];
        $visibleTags = array_filter([$productGroup, $formatState($certification), $formatState($tier)]);
        $hiddenTags = [];
    }

    $imageUrls = $imageUrls
        ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
        ->prepend(is_string($imageUrl) && $imageUrl !== '' ? $imageUrl : null)
        ->filter()
        ->unique()
        ->values();
    $initials = collect(preg_split('/\s+/', trim((string) ($label !== '' ? $label : $packageName))) ?: [])
        ->filter()
        ->map(fn (string $part): string => mb_substr($part, 0, 1))
        ->take(2)
        ->implode('');
    $initials = mb_strtoupper($initials !== '' ? $initials : 'EX');
    $selectionSelectedExpression = $isMarketplaceRecord && $composerName !== ''
        ? 'isSelected(' . Js::from($composerName) . ')'
        : 'false';
    $selectionButtonLabel = match (true) {
        ($record['is_installed'] ?? false) === true => __('capell-admin::marketplace.selection.already_installed'),
        $installInProgress => __('capell-admin::marketplace.card.install_in_progress'),
        default => __('capell-admin::marketplace.selection.select'),
    };
@endphp

@once
    <style>
        .capell-extension-card-record {
            overflow: hidden;
            position: relative;
            z-index: 0;
        }

        .capell-extension-card-record.fi-ta-record-with-content-prefix {
            grid-template-columns: minmax(0, 1fr);
        }

        .capell-extension-card-record > .fi-ta-record-checkbox {
            background: linear-gradient(
                135deg,
                rgb(255 255 255 / 0.98),
                rgb(239 246 255 / 0.96)
            );
            border-radius: 0 0 0.75rem 0;
            box-shadow:
                0 0.875rem 1.75rem -1rem rgb(15 23 42 / 0.42),
                0 0 0 1px rgb(255 255 255 / 0.9);
            inset-inline-start: 0;
            padding: 0.5rem;
            position: absolute;
            top: 0;
            z-index: 20;
        }

        :is(.dark .capell-extension-card-record > .fi-ta-record-checkbox) {
            background: linear-gradient(
                135deg,
                rgb(17 24 39 / 0.98),
                rgb(30 41 59 / 0.96)
            );
            box-shadow:
                0 0.875rem 1.75rem -1rem rgb(0 0 0 / 0.72),
                0 0 0 1px rgb(255 255 255 / 0.12);
        }

        .capell-extension-card-record > .fi-ta-record-content-ctn {
            min-width: 0;
        }

        .capell-extension-card-record .fi-ta-actions {
            align-items: center;
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            justify-content: flex-start;
            margin: 0;
            padding: 0 0.875rem 0.875rem;
            position: static;
        }

        .capell-extension-card-record .fi-ta-actions.fi-align-start {
            justify-content: flex-start;
        }

        .capell-extension-card-record .fi-ta-actions .fi-ac-action {
            justify-content: center;
            min-width: max-content;
        }

        .capell-extension-card-record
            .fi-ta-actions
            .capell-extension-card-lifecycle-action {
            margin-inline-start: auto;
        }

        .capell-extension-card-record
            .fi-ta-actions
            .capell-extension-card-details-action {
            display: none;
        }

        .capell-extension-card-record .fi-ta-actions .fi-link {
            border-radius: 0.5rem;
            border: 1px solid rgb(209 213 219 / 0.9);
            padding: 0.5rem 0.75rem;
            text-decoration-line: none;
        }

        .capell-extension-card-record .fi-ta-actions .fi-link:hover {
            background: rgb(249 250 251);
            text-decoration-line: none;
        }

        :is(.dark .capell-extension-card-record .fi-ta-actions .fi-link) {
            border-color: rgb(255 255 255 / 0.12);
        }

        :is(.dark .capell-extension-card-record .fi-ta-actions .fi-link:hover) {
            background: rgb(255 255 255 / 0.06);
        }

        .capell-extension-card-media-scroll {
            scrollbar-width: none;
        }

        .capell-extension-card-media-scroll::-webkit-scrollbar {
            display: none;
        }
    </style>
@endonce

<article
    {{
        $attributeBag->merge($extraAttributes)->class([
            'group flex h-full min-h-[24rem] flex-col p-2.5',
        ])
    }}
    @if ($isMarketplaceRecord && $composerName !== '')
        x-bind:class="
            {{ $selectionSelectedExpression }}
                ? 'bg-blue-50/40 ring-2 ring-blue-500/70 dark:bg-blue-500/10 dark:ring-blue-400/70'
                : ''
        "
    @endif
    aria-labelledby="{{ $cardTitleId }}"
>
    <figure class="relative h-28 overflow-hidden bg-gray-100 dark:bg-gray-950">
        @if (is_array($managementSurface))
            <button
                type="button"
                wire:click="mountTableAction('manageExtension', {{ Js::from($packageName) }})"
                class="absolute inset-0 z-10"
                aria-label="{{ __('capell-admin::button.manage_extension') }}: {{ $label }}"
            ></button>
        @elseif (is_string($primaryUrl) && $primaryUrl !== '')
            <a
                href="{{ $primaryUrl }}"
                class="absolute inset-0 z-10"
                aria-label="{{ __('capell-admin::button.manage_extension') }}: {{ $label }}"
            ></a>
        @endif

        <div class="size-full">
            @if ($imageUrls->isNotEmpty())
                <div
                    class="capell-extension-card-media-scroll flex size-full snap-x snap-mandatory overflow-x-auto scroll-smooth"
                >
                    @foreach ($imageUrls as $extensionImageUrl)
                        <img
                            id="extension-image-{{ hash('sha256', (string) $packageName) }}-{{ $loop->iteration }}"
                            src="{{ $extensionImageUrl }}"
                            alt="{{ $isMarketplaceRecord ? __('capell-admin::marketplace.card.image_alt', ['name' => $label]) : $label }}"
                            class="size-full shrink-0 snap-start object-cover transition duration-300 group-hover:scale-[1.02]"
                            loading="lazy"
                        />
                    @endforeach
                </div>
            @else
                <div
                    class="flex size-full items-center justify-center bg-gradient-to-br from-gray-100 via-white to-blue-50 text-4xl font-bold text-gray-400 dark:from-gray-950 dark:via-gray-900 dark:to-blue-950/30 dark:text-gray-600"
                    aria-hidden="true"
                >
                    {{ $initials }}
                </div>
            @endif
        </div>

        @if ($isMarketplaceRecord && $selectionInputId !== null)
            <button
                type="button"
                tabindex="-1"
                x-on:click.stop="
                    toggleMarketplaceRecord(
                        {{ Js::from($composerName) }},
                        {{ Js::from($label) }},
                        {{ $selectionState['selectable'] ? 'true' : 'false' }},
                    )
                "
                aria-label="{{ __('capell-admin::marketplace.selection.toggle_selection', ['name' => $label]) }}"
                aria-pressed="{{ $selectionState['selected'] ? 'true' : 'false' }}"
                x-bind:aria-pressed="{{ $selectionSelectedExpression }} ? 'true' : 'false'"
                data-marketplace-selection-icon="{{ $composerName }}"
                @disabled(! $selectionState['selectable'])
                @class([
                    'absolute top-0 left-0 z-20 inline-flex size-11 shrink-0 items-center justify-center rounded-br-xl bg-gradient-to-br shadow-lg ring-1 transition focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none dark:focus:ring-offset-gray-900',
                    'from-gray-100/95 to-gray-200/90 text-gray-400 ring-gray-200 dark:from-gray-900/90 dark:to-gray-800/90 dark:text-gray-500 dark:ring-white/10' => ! $selectionState['selectable'],
                ])
                x-bind:class="
                    {{ $selectionSelectedExpression }}
                        ? 'from-blue-600 to-indigo-600 text-white ring-blue-500/70'
                        : '{{ $selectionState['selectable'] ? 'from-white/95 to-blue-50/95 text-gray-800 ring-white/80 dark:from-gray-950/95 dark:to-gray-800/95 dark:text-gray-200 dark:ring-white/20' : 'from-gray-100/95 to-gray-200/90 text-gray-400 ring-gray-200 dark:from-gray-900/90 dark:to-gray-800/90 dark:text-gray-500 dark:ring-white/10' }}'
                "
            >
                <span
                    x-show="{{ $selectionSelectedExpression }}"
                    @if (! $selectionState['selected'])
                        x-cloak
                    @endif
                >
                    @svg('heroicon-o-check', 'h-5 w-5 stroke-[2.5]')
                </span>
                <span
                    x-show="! {{ $selectionSelectedExpression }}"
                    @if ($selectionState['selected'])
                        x-cloak
                    @endif
                >
                    @svg('heroicon-o-plus', 'h-5 w-5 stroke-[2.5]')
                </span>
            </button>
        @endif

        @if ($imageUrls->count() > 1)
            <div
                class="absolute inset-x-0 bottom-20 z-20 flex justify-center gap-1.5"
            >
                @foreach ($imageUrls as $extensionImageUrl)
                    <a
                        href="#extension-image-{{ hash('sha256', (string) $packageName) }}-{{ $loop->iteration }}"
                        class="size-1.5 rounded-full bg-white/70 ring-1 ring-black/20 transition hover:bg-white"
                        aria-label="{{ __('capell-admin::marketplace.detail.screenshots_heading') }} {{ $loop->iteration }}"
                    ></a>
                @endforeach
            </div>
        @endif

        @if ($showBlockedTag || $updateAvailable || $healthState !== 'ok' || $installInProgress)
            <div
                class="absolute top-3 right-3 z-20 flex flex-wrap justify-end gap-1.5"
            >
                @if ($installInProgress)
                    <span
                        class="bg-info-500/95 inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold text-white ring-1 ring-white/20"
                    >
                        {{ __('capell-admin::marketplace.card.install_in_progress') }}
                    </span>
                @endif

                @if ($showBlockedTag)
                    <span
                        class="bg-danger-500/95 inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold text-white ring-1 ring-white/20"
                    >
                        {{ $isMarketplaceRecord ? $formatState($record['marketplace_install_state'] ?? 'blocked') : __('capell-admin::generic.extension_operations_tab_blocked') }}
                    </span>
                @endif

                @if ($updateAvailable)
                    <span
                        class="bg-warning-500/95 inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold text-white ring-1 ring-white/20"
                    >
                        {{ __('capell-admin::generic.extension_operations_tab_updates') }}
                    </span>
                @endif

                @if ($healthState !== 'ok')
                    <span
                        class="bg-danger-500/95 inline-flex items-center rounded-md px-2 py-1 text-xs font-semibold text-white ring-1 ring-white/20"
                    >
                        {{ $formatState($healthState) }}
                    </span>
                @endif
            </div>
        @endif

        <div
            class="pointer-events-none absolute inset-x-0 bottom-0 z-20 flex items-end justify-between gap-3 bg-gradient-to-t from-black/95 via-black/70 to-transparent p-3 pt-12"
        >
            <div
                class="min-w-0 rounded-lg border border-white/20 bg-gray-950/75 px-2.5 py-2 shadow-lg shadow-black/20 backdrop-blur-sm"
            >
                <p class="text-xs font-semibold text-white/85 uppercase">
                    {{ $formatState($productGroup ?? __('capell-admin::generic.extensions')) }}
                </p>
                <h3
                    id="{{ $cardTitleId }}"
                    class="mt-0.5 truncate text-base font-semibold text-white"
                >
                    {{ $label }}
                </h3>

                @if ($isMarketplaceRecord && $ratingStars !== [])
                    <div
                        class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-white/85"
                    >
                        <span
                            class="flex items-center gap-1"
                            role="img"
                            aria-label="{{ $ratingAriaLabel }}"
                        >
                            @foreach ($ratingStars as $ratingStar)
                                <span
                                    class="relative inline-flex size-3.5 text-white/35"
                                    aria-hidden="true"
                                >
                                    <span>★</span>

                                    @if ($ratingStar !== 'empty')
                                        <span
                                            @class([
                                                'text-warning-400 absolute inset-0 overflow-hidden',
                                                'w-1/2' => $ratingStar === 'half',
                                                'w-full' => $ratingStar === 'full',
                                            ])
                                        >
                                            ★
                                        </span>
                                    @endif
                                </span>
                            @endforeach
                        </span>

                        <span>
                            {{ $ratingAverage !== null ? $ratingAverageLabel . ' · ' . $ratingsCountLabel : $ratingsCountLabel }}
                        </span>
                    </div>
                @endif
            </div>
        </div>
    </figure>

    <div class="flex flex-1 flex-col gap-2.5 p-2">
        <p
            class="line-clamp-2 text-sm leading-5 text-gray-600 dark:text-gray-300"
        >
            {{ $description }}
        </p>

        <div
            class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-300"
        >
            @if (is_string($authorName) && $authorName !== '')
                @if ($isMarketplaceRecord && $authorFilter !== null)
                    <button
                        type="button"
                        wire:click="filterByMarketplaceAuthor(@js($authorFilter), @js($authorName))"
                        class="font-semibold text-blue-600 hover:text-blue-500 hover:underline dark:text-blue-400"
                        title="{{ __('capell-admin::marketplace.card.author_filter_tooltip', ['author' => $authorName]) }}"
                        aria-label="{{ __('capell-admin::marketplace.card.author_filter_tooltip', ['author' => $authorName]) }}"
                    >
                        {{ $authorName }}
                    </button>
                @elseif (is_string($externalUrl) && $externalUrl !== '')
                    <a
                        href="{{ $externalUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-semibold text-blue-600 hover:text-blue-500 hover:underline dark:text-blue-400"
                    >
                        {{ $authorName }}
                    </a>
                @else
                    <span
                        class="font-semibold text-gray-800 dark:text-gray-100"
                    >
                        {{ $authorName }}
                    </span>
                @endif
            @elseif ($isMarketplaceRecord)
                <span class="font-semibold text-gray-800 dark:text-gray-100">
                    {{ __('capell-admin::marketplace.card.unknown_author') }}
                </span>
            @endif

            @foreach ($visibleTags as $tag)
                <span>{{ $formatState($tag) }}</span>
            @endforeach

            @if ($hiddenTags !== [])
                <span
                    title="{{ collect($hiddenTags)->map($formatState)->implode(', ') }}"
                >
                    {{ __('capell-admin::marketplace.card.more_tags', ['count' => count($hiddenTags)]) }}
                </span>
            @endif
        </div>

        <div
            class="mt-auto flex items-end justify-between gap-3 border-t border-gray-950/5 pt-2 text-sm dark:border-white/10"
        >
            <div class="min-w-0">
                <div
                    class="text-xs font-medium text-gray-400 uppercase dark:text-gray-500"
                >
                    {{ __('capell-admin::table.version') }}
                </div>
                <div
                    class="mt-1 truncate font-semibold text-gray-950 dark:text-white"
                >
                    {{ $version ?? __('capell-admin::generic.unknown') }}
                </div>
            </div>

            <div class="flex min-w-0 items-end gap-3 text-right">
                @if ($showPrice)
                    <div class="min-w-0">
                        <div
                            class="text-xs font-medium text-gray-400 uppercase dark:text-gray-500"
                        >
                            {{ __('capell-admin::marketplace.card.price_label') }}
                        </div>
                        <div
                            class="mt-1 truncate font-semibold text-gray-950 dark:text-white"
                        >
                            {{ $priceLabel }}
                        </div>
                    </div>
                @elseif ($updateAvailable && is_string($latestVersion) && $latestVersion !== '')
                    <div class="min-w-0">
                        <div
                            class="text-xs font-medium text-gray-400 uppercase dark:text-gray-500"
                        >
                            {{ __('capell-admin::table.latest_version') }}
                        </div>
                        <div
                            class="mt-1 truncate font-semibold text-gray-950 dark:text-white"
                        >
                            {{ $latestVersion }}
                        </div>
                    </div>
                @endif

                @if ($isMarketplaceRecord && $composerName !== '')
                    <button
                        type="button"
                        x-on:click.stop="
                            toggleMarketplaceRecord(
                                {{ Js::from($composerName) }},
                                {{ Js::from($label) }},
                                {{ $selectionState['selectable'] ? 'true' : 'false' }},
                            )
                        "
                        aria-label="{{ __('capell-admin::marketplace.selection.toggle_selection', ['name' => $label]) }}"
                        aria-pressed="{{ $selectionState['selected'] ? 'true' : 'false' }}"
                        x-bind:aria-pressed="{{ $selectionSelectedExpression }} ? 'true' : 'false'"
                        data-marketplace-selection-primary="{{ $composerName }}"
                        @disabled(! $selectionState['selectable'])
                        @if (! $selectionState['selectable'] && is_string($selectionState['reason']))
                            title="{{ $selectionState['reason'] }}"
                            aria-label="{{ $selectionState['reason'] }}"
                        @endif
                        @class([
                            'relative z-20 inline-flex shrink-0 items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold transition focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none dark:focus:ring-offset-gray-900',
                            'cursor-not-allowed bg-gray-100 text-gray-400 dark:bg-white/10 dark:text-gray-500' => ! $selectionState['selectable'],
                        ])
                        x-bind:class="
                            {{ $selectionSelectedExpression }}
                                ? 'bg-blue-50 text-blue-700 ring-1 ring-blue-600/20 hover:bg-blue-100 dark:bg-blue-500/10 dark:text-blue-200'
                                : '{{ $selectionState['selectable'] ? 'bg-blue-600 text-white shadow-sm hover:bg-blue-500 dark:bg-blue-500 dark:hover:bg-blue-400' : 'cursor-not-allowed bg-gray-100 text-gray-400 dark:bg-white/10 dark:text-gray-500' }}'
                        "
                    >
                        <span
                            x-show="! {{ $selectionSelectedExpression }}"
                            @if ($selectionState['selected'])
                                x-cloak
                            @endif
                        >
                            {{ $selectionButtonLabel }}
                        </span>
                        <span
                            x-show="{{ $selectionSelectedExpression }}"
                            @if (! $selectionState['selected'])
                                x-cloak
                            @endif
                        >
                            {{ __('capell-admin::marketplace.selection.selected') }}
                        </span>
                    </button>
                @endif

                <button
                    type="button"
                    wire:click="mountTableAction('viewExtensionDetails', {{ Js::from($packageName) }})"
                    class="relative z-20 inline-flex size-9 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-500 shadow-sm transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-600 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:outline-none dark:border-white/10 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-blue-500/40 dark:hover:bg-blue-500/10 dark:hover:text-blue-300"
                    aria-label="{{ __('capell-admin::button.view_details') }}: {{ $label }}"
                >
                    @svg('heroicon-o-question-mark-circle', 'h-5 w-5')
                </button>
            </div>
        </div>
    </div>
</article>
