@php
    use Illuminate\Support\Js;

    $selection = $marketplaceResultsFetched && $marketplaceStep === 'review'
        ? $this->marketplaceSelectionReview()
        : null;
    $initialSelectedRecords = collect($selection['explicit_records'] ?? $selectedMarketplaceComposerNames)
        ->map(fn (array|string $record): array => is_array($record) ? $record : [
            'composer_name' => $record,
            'name' => $record,
        ])
        ->map(fn (array $record): array => [
            'composerName' => is_string($record['composer_name'] ?? null) ? $record['composer_name'] : '',
            'name' => is_string($record['name'] ?? null) ? $record['name'] : (is_string($record['composer_name'] ?? null) ? $record['composer_name'] : ''),
        ])
        ->filter(fn (array $record): bool => $record['composerName'] !== '')
        ->values()
        ->all();
@endphp

<div
    x-data="{
        selectedRecordsByComposerName: Object.fromEntries(
            {{ Js::from($initialSelectedRecords) }}.map((record) => [
                record.composerName,
                record,
            ]),
        ),
        selectedRecords() {
            return Object.values(this.selectedRecordsByComposerName)
        },
        selectedComposerNames() {
            return Object.keys(this.selectedRecordsByComposerName)
        },
        selectedCount() {
            return this.selectedComposerNames().length
        },
        isSelected(composerName) {
            return Object.prototype.hasOwnProperty.call(
                this.selectedRecordsByComposerName,
                composerName,
            )
        },
        removeMarketplaceRecord(composerName) {
            if (! this.isSelected(composerName)) {
                return
            }

            const selectedRecordsByComposerName = {
                ...this.selectedRecordsByComposerName,
            }

            delete selectedRecordsByComposerName[composerName]

            this.selectedRecordsByComposerName = selectedRecordsByComposerName
        },
        toggleMarketplaceRecord(composerName, name, selectable = true) {
            if (! selectable || ! composerName) {
                return
            }

            if (this.isSelected(composerName)) {
                this.removeMarketplaceRecord(composerName)

                return
            }

            this.selectedRecordsByComposerName = {
                ...this.selectedRecordsByComposerName,
                [composerName]: {
                    composerName,
                    name: name || composerName,
                },
            }
        },
        clearMarketplaceSelection() {
            this.selectedRecordsByComposerName = {}
            this.$wire.set('selectedMarketplaceComposerNames', [], false)
        },
        reviewMarketplaceSelection() {
            this.$wire.set(
                'selectedMarketplaceComposerNames',
                this.selectedComposerNames(),
                false,
            )
            this.$wire.showMarketplaceInstallReview()
        },
    }"
    class="space-y-4"
    @if (! $marketplaceResultsFetched)
        wire:init="loadMarketplaceResults"
    @endif
>
    @if ($marketplaceResultsFetched)
        <div class="relative min-h-80 space-y-4">
            <div
                wire:loading.flex
                class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-white/80 backdrop-blur-sm dark:bg-gray-950/70"
                role="status"
                aria-live="polite"
                aria-label="{{ __('capell-marketplace::marketplace.filters.loading_heading') }}"
            >
                <div
                    class="flex flex-col items-center gap-3 rounded-lg border border-gray-200 bg-white px-6 py-5 text-center shadow-sm dark:border-white/10 dark:bg-gray-900"
                >
                    <x-filament::loading-indicator
                        class="h-12 w-12 text-blue-600 dark:text-blue-400"
                    />
                    <span
                        class="text-sm font-medium text-gray-700 dark:text-gray-200"
                    >
                        {{ __('capell-marketplace::marketplace.filters.loading_heading') }}
                    </span>
                </div>
            </div>

            @if ($marketplaceStep === 'review')
                @include('capell-marketplace::filament.livewire.marketplace-extensions-browser.install-review', [
                    'selection' => $selection,
                ])
            @else
                {{ $this->table }}

                @include('capell-marketplace::filament.livewire.marketplace-extensions-browser.review-footer')
            @endif
        </div>
    @else
        <div
            class="flex min-h-80 items-center justify-center rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm dark:border-white/10 dark:bg-gray-900"
            role="status"
            aria-live="polite"
        >
            <div class="mx-auto flex max-w-md flex-col items-center gap-3">
                <x-filament::loading-indicator
                    class="h-14 w-14 text-blue-600 dark:text-blue-400"
                />
                <h3
                    class="text-base font-semibold text-gray-950 dark:text-white"
                >
                    {{ __('capell-marketplace::marketplace.filters.loading_heading') }}
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('capell-marketplace::marketplace.filters.loading_description') }}
                </p>
            </div>
        </div>
    @endif

    <x-filament-actions::modals />
</div>
