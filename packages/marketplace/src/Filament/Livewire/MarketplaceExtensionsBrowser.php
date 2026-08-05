<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Livewire;

use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\BuildMarketplaceSelectionReviewAction;
use Capell\Marketplace\Actions\EvaluateMarketplaceEnvironmentReadinessAction;
use Capell\Marketplace\Actions\QueueMarketplaceBulkUpdateAction;
use Capell\Marketplace\Actions\RecordThemeInstallIntentAction;
use Capell\Marketplace\Actions\StartMarketplaceInstallFlowAction;
use Capell\Marketplace\Actions\UpdateMarketplaceExtensionAction;
use Capell\Marketplace\Data\CreateMarketplaceInstallFlowSessionData;
use Capell\Marketplace\Data\MarketplaceEnvironmentReadinessData;
use Capell\Marketplace\Data\MarketplaceInstallActorData;
use Capell\Marketplace\Data\MarketplaceSelectionInputData;
use Capell\Marketplace\Data\MarketplaceSelectionRecordData;
use Capell\Marketplace\Enums\ExtensionKind;
use Capell\Marketplace\Enums\MarketplaceInstallFailureStage;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Enums\MarketplaceInstallState;
use Capell\Marketplace\Filament\Pages\MarketplaceExtensionDetailPage;
use Capell\Marketplace\Filament\Pages\MarketplacePackageOperationsPage;
use Capell\Marketplace\Filament\Pages\MarketplacePage;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueTable;
use Capell\Marketplace\Filament\Support\MarketplaceErrorPresenter;
use Capell\Marketplace\Filament\Support\MarketplaceInstallActionPresenter;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Contracts\TranslatableContentDriver;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Throwable;

final class MarketplaceExtensionsBrowser extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    private const string STEP_BROWSE = 'browse';

    private const string STEP_REVIEW = 'review';

    /**
     * Confirming an install used to navigate the operator away from the modal to
     * the operations page. That is a page load, a lost catalogue position, and a
     * context switch imposed at the exact moment the thing they asked for starts
     * working. The modal now shows the operation running, and the operations page
     * stays one click away for anyone who wants the full timeline.
     */
    private const string STEP_PROGRESS = 'progress';

    public ?string $lockedKind = null;

    public ?string $initialSearch = null;

    public string $marketplaceStep = self::STEP_BROWSE;

    public bool $includeLocalExtensionState = true;

    public bool $marketplaceResultsFetched = false;

    /** @var array<int, string> */
    public array $selectedMarketplaceComposerNames = [];

    public bool $installReviewedMarketplaceExtensionsConfirmed = false;

    public bool $betaMarketplaceExtensionsAcknowledged = false;

    public ?string $marketplaceLicenseKey = null;

    /**
     * Default OFF, and it says so on the label. Applying a theme replaces what
     * every visitor sees; opting into that is a decision, not a default.
     */
    public bool $activateMarketplaceThemesAfterInstall = false;

    /** @var array<int, int> */
    public array $activeMarketplaceInstallAttemptIds = [];

    /** @var array<string, mixed> */
    public array $selectedMarketplaceInstallOptions = [];

    /** @var array<string, mixed>|null */
    private ?array $resolvedMarketplaceSelectionReview = null;

    private ?MarketplaceEnvironmentReadinessData $marketplaceEnvironmentReadiness = null;

    public function mount(?string $lockedKind = null, bool $includeLocalExtensionState = true, ?string $initialSearch = null): void
    {
        $this->lockedKind = $lockedKind;
        $this->includeLocalExtensionState = $includeLocalExtensionState;
        $this->initialSearch = $initialSearch;

        if (filled($initialSearch)) {
            $this->tableSearch = trim($initialSearch);
        }

        $this->tableFilters['installed_status']['value'] = false;

        $this->authorizeMarketplaceAccess();
    }

    public function loadMarketplaceResults(): void
    {
        $this->authorizeMarketplaceAccess();

        resolve(MarketplaceCatalogueRecordProvider::class)->queueDefaultWarm(
            lockedKind: $this->lockedKind,
            includeLocalExtensionState: $this->includeLocalExtensionStateForBrowser(),
        );

        $this->marketplaceResultsFetched = true;
    }

    public function filterByMarketplaceAuthor(string $author, ?string $label = null): void
    {
        $this->authorizeMarketplaceAccess();

        if ($author === '') {
            return;
        }

        $this->tableFilters['author']['author'] = $label !== null && $label !== '' ? $label : $author;
        $this->tableFilters['author']['author_slug'] = $author;
        $this->resetPage();
    }

    public function toggleMarketplaceSelection(string $composerName): void
    {
        $this->authorizeMarketplaceAccess();

        $composerName = trim($composerName);

        if ($composerName === '') {
            return;
        }

        $records = $this->currentMarketplaceRecordsByComposerName();
        $record = $records[$composerName] ?? null;

        if (! is_array($record) || ! $this->marketplaceRecordIsSelectable($record)) {
            return;
        }

        $selectedComposerNames = $this->normalizedSelectedMarketplaceComposerNames();

        if (in_array($composerName, $selectedComposerNames, true)) {
            $this->selectedMarketplaceComposerNames = array_values(array_diff($selectedComposerNames, [$composerName]));
            $this->resolvedMarketplaceSelectionReview = null;

            return;
        }

        $this->selectedMarketplaceComposerNames = [...$selectedComposerNames, $composerName];
        $this->resolvedMarketplaceSelectionReview = null;
    }

    public function clearMarketplaceSelection(): void
    {
        $this->authorizeMarketplaceAccess();

        $this->selectedMarketplaceComposerNames = [];
        $this->resolvedMarketplaceSelectionReview = null;
        $this->installReviewedMarketplaceExtensionsConfirmed = false;
        $this->betaMarketplaceExtensionsAcknowledged = false;
        $this->marketplaceStep = self::STEP_BROWSE;
    }

    public function installMarketplaceRecordFromCard(string $composerName): void
    {
        $this->authorizeMarketplaceAccess();

        $composerName = trim($composerName);

        if ($composerName === '') {
            return;
        }

        $record = $this->currentMarketplaceRecordsByComposerName()[$composerName] ?? null;

        if (! is_array($record) || ! $this->marketplaceRecordIsSelectable($record)) {
            Notification::make()
                ->warning()
                ->title((string) __('capell-marketplace::marketplace.selection.unavailable_title'))
                ->body($this->marketplaceRecordSelectionBlockReason($record ?? []) ?? (string) __('capell-marketplace::marketplace.selection.unavailable_body'))
                ->send();

            return;
        }

        $selectedComposerNames = $this->normalizedSelectedMarketplaceComposerNames();

        if (! in_array($composerName, $selectedComposerNames, true)) {
            $this->selectedMarketplaceComposerNames = [...$selectedComposerNames, $composerName];
            $this->resolvedMarketplaceSelectionReview = null;
        }

        $this->showMarketplaceInstallReview();
    }

    /**
     * Whether the Update button belongs on this record's card.
     *
     * Delegates to the presenter rather than re-reading has_update_available
     * here, so the card, the table and the detail page cannot disagree about
     * whether an update is on offer.
     *
     * @param  array<string, mixed>  $record
     */
    public function marketplaceRecordCanUpdate(array $record): bool
    {
        return resolve(MarketplaceInstallActionPresenter::class)->canUpdate($record);
    }

    /**
     * One-click update straight from a card.
     *
     * Queued rather than run: the operator stays where they are, and the
     * operation gets the same lock, health check and rollback protection every
     * other Composer operation on this release gets.
     */
    public function updateMarketplaceRecordFromCard(string $composerName): void
    {
        $this->authorizeMarketplaceAccess();

        $composerName = trim($composerName);

        if ($composerName === '') {
            return;
        }

        try {
            $attempt = UpdateMarketplaceExtensionAction::run(
                composerName: $composerName,
                actor: $this->marketplaceUpdateActor(),
            );
        } catch (ValidationException $validationException) {
            Notification::make()
                ->warning()
                ->title((string) __('capell-marketplace::marketplace.selection.unavailable_title'))
                ->body(collect($validationException->errors())->flatten()->first()
                    ?? (string) __('capell-marketplace::marketplace.selection.unavailable_body'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title((string) __('capell-marketplace::marketplace.updates.queued_title'))
            ->body((string) __('capell-marketplace::marketplace.updates.queued_body', [
                'name' => $attempt->extension_name,
            ]))
            ->send();

        $this->activeMarketplaceInstallAttemptIds = [(int) $attempt->getKey()];
        $this->marketplaceStep = self::STEP_PROGRESS;
    }

    /**
     * Update everything the operator has selected.
     *
     * One attempt per extension, serialised by the global Composer lock the jobs
     * already contend for. A single summary at the end, because an operator who
     * selected eight extensions does not want eight toasts.
     */
    public function updateSelectedMarketplaceRecords(): void
    {
        $this->authorizeMarketplaceAccess();

        $composerNames = $this->normalizedSelectedMarketplaceComposerNames();

        if ($composerNames === []) {
            return;
        }

        $result = QueueMarketplaceBulkUpdateAction::run(
            composerNames: $composerNames,
            actor: $this->marketplaceUpdateActor(),
        );

        if (! $result->queuedAnything()) {
            Notification::make()
                ->warning()
                ->title((string) __('capell-marketplace::marketplace.updates.bulk_none_title'))
                ->body($result->skipped === []
                    ? (string) __('capell-marketplace::marketplace.updates.bulk_none_body')
                    : $result->summaryBody())
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title((string) __('capell-marketplace::marketplace.updates.bulk_queued_title'))
            ->body($result->summaryBody())
            ->persistent()
            ->send();

        $this->selectedMarketplaceComposerNames = [];
        $this->resolvedMarketplaceSelectionReview = null;
        $this->activeMarketplaceInstallAttemptIds = array_values($result->queuedAttemptIds);
        $this->marketplaceStep = self::STEP_PROGRESS;
    }

    public function showMarketplaceInstallReview(): void
    {
        $this->authorizeMarketplaceAccess();

        $selection = $this->marketplaceSelectionReview();

        if ($selection['install_records'] === []) {
            Notification::make()
                ->warning()
                ->title((string) __('capell-marketplace::marketplace.selection.unavailable_title'))
                ->body((string) __('capell-marketplace::marketplace.selection.unavailable_body'))
                ->send();

            return;
        }

        $this->marketplaceStep = self::STEP_REVIEW;
        $this->activeMarketplaceInstallAttemptIds = [];
        $this->installReviewedMarketplaceExtensionsConfirmed = false;
        $this->betaMarketplaceExtensionsAcknowledged = false;
        $this->selectedMarketplaceInstallOptions = [
            ...$this->defaultMarketplaceInstallOptions($selection['install_records']),
            ...$this->selectedMarketplaceInstallOptions,
        ];
    }

    public function backToMarketplaceTable(): void
    {
        $this->authorizeMarketplaceAccess();

        $this->marketplaceStep = self::STEP_BROWSE;
    }

    public function installReviewedMarketplaceExtensions(): void
    {
        $this->authorizeMarketplaceAccess();

        $selection = $this->marketplaceSelectionReview();

        // A host that cannot run an automated install must not be able to reach
        // a preflight failure through the confirm path; it gets the manual
        // instructions instead.
        if (! $this->marketplaceEnvironmentReadiness()->canInstallAutomatically()
            || ! $selection['can_install']
            || ! $this->installReviewedMarketplaceExtensionsConfirmed
            || ($selection['contains_beta'] && ! $this->betaMarketplaceExtensionsAcknowledged)) {
            Notification::make()
                ->warning()
                ->title((string) __('capell-marketplace::marketplace.selection.unavailable_title'))
                ->body((string) __('capell-marketplace::marketplace.selection.unavailable_body'))
                ->send();

            return;
        }

        if ($this->marketplaceSelectionRequiresLicenceKey($selection)) {
            $this->validate([
                'marketplaceLicenseKey' => ['required', 'string', 'max:512'],
            ], [], [
                'marketplaceLicenseKey' => (string) __('capell-marketplace::marketplace.install.license_key_label'),
            ]);
        }

        if ($this->marketplaceSelectionNeedsHostedFlow($selection)) {
            try {
                $this->redirect(StartMarketplaceInstallFlowAction::run(new CreateMarketplaceInstallFlowSessionData(
                    selectedExtensions: $this->marketplaceInstallFlowSelections($selection['install_records']),
                    installOptions: [
                        ...$this->selectedMarketplaceInstallOptionsByRecord($selection['install_records']),
                        'beta_acknowledged' => $selection['contains_beta'] && $this->betaMarketplaceExtensionsAcknowledged,
                    ],
                    dependencySnapshot: [
                        'missing_dependencies' => $selection['missing_dependencies'],
                        'blocked_dependencies' => $selection['blocked_dependencies'],
                        'dependency_composer_names' => $selection['dependency_composer_names'],
                    ],
                    userContext: [
                        'user_id' => auth()->id() !== null ? (string) auth()->id() : null,
                        'user_email' => auth()->user()?->email,
                    ],
                    returnUrl: route('capell-marketplace.install-flow.callback'),
                )));

                return;
            } catch (Throwable $throwable) {
                $fallbackUrl = $this->marketplaceSelectionFallbackPurchaseUrl($selection['premium_records']);

                if ($fallbackUrl !== null) {
                    $this->redirect($fallbackUrl);

                    return;
                }

                MarketplaceErrorPresenter::notification(
                    (string) __('capell-marketplace::marketplace.install_flow.failed_title'),
                    $throwable,
                )->send();

                return;
            }
        }

        $installComposerNames = $selection['install_composer_names'];

        foreach ($selection['install_records'] as $record) {
            try {
                $redirectUrl = resolve(MarketplaceCatalogueTable::class)->installExtension(
                    arguments: $record,
                    data: [
                        'license_key' => $this->marketplaceLicenseKey,
                        '_validation_errors' => true,
                        'install_options' => [
                            ...$this->selectedMarketplaceInstallOptionsForRecords([$record]),
                            ...$this->themeActivationInstallOption($record),
                            'beta_acknowledged' => $selection['contains_beta'] && $this->betaMarketplaceExtensionsAcknowledged,
                        ],
                    ],
                    redirectAccountActions: true,
                );
            } catch (ValidationException $validationException) {
                $message = collect($validationException->errors())->flatten()->first();
                $this->addError('marketplaceLicenseKey', is_string($message)
                    ? $message
                    : (string) __('capell-marketplace::marketplace.install.license_key_invalid'));

                return;
            }

            if (is_string($redirectUrl) && $redirectUrl !== '') {
                $this->redirect($redirectUrl);

                return;
            }
        }

        $this->selectedMarketplaceComposerNames = [];
        $this->selectedMarketplaceInstallOptions = [];
        $this->marketplaceLicenseKey = null;
        $this->installReviewedMarketplaceExtensionsConfirmed = false;
        $this->resolvedMarketplaceSelectionReview = null;
        $this->activeMarketplaceInstallAttemptIds = $this->activeMarketplaceInstallAttemptIdsFor($installComposerNames);
        $this->marketplaceStep = self::STEP_PROGRESS;
    }

    /**
     * The live state of the operations this modal started.
     *
     * Read straight from the attempts rather than mirrored into component state:
     * the job writes current_stage, progress_current and heartbeat_at as it goes,
     * and a second copy of that in a Livewire property is a copy that can be
     * stale at exactly the moment the operator is watching it.
     *
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     composer_name: string,
     *     status: string,
     *     stage: ?string,
     *     stage_label: string,
     *     progress_current: int,
     *     progress_total: int,
     *     active: bool,
     *     succeeded: bool,
     *     failure_reason: ?string
     * }>
     */
    public function marketplaceInstallProgress(): array
    {
        $this->authorizeMarketplaceAccess();

        if ($this->activeMarketplaceInstallAttemptIds === []) {
            return [];
        }

        return MarketplaceInstallAttempt::query()
            ->whereKey($this->activeMarketplaceInstallAttemptIds)
            ->orderBy('id')
            ->get()
            ->map(fn (MarketplaceInstallAttempt $attempt): array => [
                'id' => (int) $attempt->getKey(),
                'name' => $attempt->extension_name,
                'composer_name' => $attempt->composer_name,
                'status' => $attempt->status->value,
                'stage' => $attempt->current_stage,
                'stage_label' => $this->marketplaceInstallStageLabel($attempt),
                'progress_current' => max(0, $attempt->progress_current ?? 0),
                'progress_total' => max(1, $attempt->progress_total ?? 5),
                'active' => $attempt->status->isActiveInstallOperation(),
                'succeeded' => $attempt->status === MarketplaceInstallIntentStatus::Succeeded,
                'failure_reason' => $attempt->failure_reason,
            ])
            ->all();
    }

    /**
     * Whether anything this modal started is still running, which is what the
     * view polls on. Polling stops the moment nothing is active — a modal that
     * keeps hitting the server after every operation finished is a background
     * cost nobody asked for.
     */
    public function hasActiveMarketplaceInstalls(): bool
    {
        return array_any($this->marketplaceInstallProgress(), fn (array $progress) => $progress['active']);
    }

    /**
     * The escape hatch to the full timeline. The redirect the confirm step used
     * to perform automatically is still here — it is now something the operator
     * chooses rather than something done to them.
     */
    public function viewMarketplaceInstallOperations(): void
    {
        $this->authorizeMarketplaceAccess();

        $this->redirect(MarketplacePackageOperationsPage::getUrl(array_filter([
            'tab' => 'active',
            'operation' => $this->activeMarketplaceInstallAttemptIds[0] ?? null,
        ])));
    }

    public function backToMarketplaceBrowseFromProgress(): void
    {
        $this->authorizeMarketplaceAccess();

        $this->activeMarketplaceInstallAttemptIds = [];
        $this->marketplaceStep = self::STEP_BROWSE;
    }

    /**
     * Whether the review screen should offer to apply a theme once it is
     * installed. Only asked when a theme is actually being installed — an
     * operator installing a plugin has no business being shown a theme question.
     */
    public function marketplaceSelectionContainsTheme(): bool
    {
        foreach ($this->marketplaceSelectionReview()['install_records'] as $record) {
            if (($record['kind'] ?? null) === ExtensionKind::Theme->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{selectable: bool, selected: bool, dependency: bool, reason: ?string}
     */
    public function marketplaceSelectionState(array $record): array
    {
        $selectionRecord = $this->marketplaceSelectionRecord($record);
        $selectedComposerNames = $this->normalizedSelectedMarketplaceComposerNames();

        return [
            'selectable' => $selectionRecord->isSelectable(),
            'selected' => $selectionRecord->composerName !== null
                && in_array($selectionRecord->composerName, $selectedComposerNames, true),
            'dependency' => false,
            'reason' => $selectionRecord->failureReasonCode !== null
                ? $this->marketplaceSelectionFailureReasonLabel($selectionRecord->failureReasonCode)
                : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function marketplaceRecords(): array
    {
        $this->authorizeMarketplaceAccess();

        return resolve(MarketplaceCatalogueRecordProvider::class)->paginatedRecords(
            search: $this->tableSearch,
            filters: $this->availableMarketplaceFilters(),
            lockedKind: $this->lockedKind,
            page: (int) ($this->paginators['page'] ?? 1),
            perPage: (int) ($this->tableRecordsPerPage ?? 18),
            includeLocalExtensionState: $this->includeLocalExtensionStateForBrowser(),
        )->items();
    }

    /** @return array{kind: array<string, string>, category: array<string, string>, sort: array<string, string>} */
    public function marketplaceFilterOptions(): array
    {
        $catalogueTable = resolve(MarketplaceCatalogueTable::class);

        return [
            'kind' => $catalogueTable->getKindOptions(),
            'category' => $catalogueTable->getCategoryOptions(),
            'sort' => $catalogueTable->getSortOptions(),
        ];
    }

    public function applyMarketplacePreset(string $preset): void
    {
        $this->authorizeMarketplaceAccess();

        match ($preset) {
            'free' => $this->tableFilters['free_only']['isActive'] = ! (bool) ($this->tableFilters['free_only']['isActive'] ?? false),
            'themes' => $this->tableFilters['kind']['value'] = 'theme',
            default => $this->tableFilters['sort']['value'] = 'recommended',
        };

        $this->tableFilters['installed_status']['value'] = false;
        $this->resetPage();
        $this->resolvedMarketplaceSelectionReview = null;
    }

    public function table(Table $table): Table
    {
        $this->authorizeMarketplaceAccess();

        return resolve(MarketplaceCatalogueTable::class)->configure(
            table: $table,
            lockedKind: $this->lockedKind,
            includeLocalExtensionState: $this->includeLocalExtensionStateForBrowser(),
            forceAvailableOnly: true,
        );
    }

    /**
     * The host's install capability, so the catalogue can say what this site can
     * do before anyone selects an extension rather than after they confirm one.
     */
    public function marketplaceEnvironmentReadiness(): MarketplaceEnvironmentReadinessData
    {
        return $this->marketplaceEnvironmentReadiness ??= EvaluateMarketplaceEnvironmentReadinessAction::run();
    }

    /**
     * Where an operator goes to read the commands they must run themselves.
     *
     * A host that cannot install for the user still has a fully browsable
     * catalogue, so the manual instructions on the extension detail page become
     * the primary call to action rather than a hidden disclosure.
     */
    public function manualInstallInstructionsUrl(string $slug): ?string
    {
        if ($slug === '') {
            return null;
        }

        try {
            return MarketplaceExtensionDetailPage::getUrl(['slug' => $slug]);
        } catch (Throwable) {
            // A panel that has not registered the detail page still gets the
            // explanation, just without a link to follow.
            return null;
        }
    }

    public function render(): mixed
    {
        return view('capell-marketplace::filament.livewire.marketplace-extensions-browser');
    }

    public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
    {
        return null;
    }

    /**
     * @return array{
     *     explicit_records: array<int, array<string, mixed>>,
     *     dependency_records: array<int, array<string, mixed>>,
     *     install_records: array<int, array<string, mixed>>,
     *     install_composer_names: array<int, string>,
     *     dependency_composer_names: array<int, string>,
     *     missing_dependencies: array<int, string>,
     *     blocked_dependencies: array<int, array{name: string, composer_name: string, reason: ?string}>,
     *     premium_records: array<int, array<string, mixed>>,
     *     selected_count: int,
     *     install_count: int,
     *     total_cents: int,
     *     total_label: string,
     *     has_premium_records: bool,
     *     contains_beta: bool,
     *     beta_dependency_composer_names: array<int, string>,
     *     impact_records: array<int, array<string, mixed>>,
     *     can_install: bool
     * }
     */
    public function marketplaceSelectionReview(): array
    {
        if ($this->resolvedMarketplaceSelectionReview !== null) {
            /** @var array{
             *     explicit_records: array<int, array<string, mixed>>,
             *     dependency_records: array<int, array<string, mixed>>,
             *     install_records: array<int, array<string, mixed>>,
             *     install_composer_names: array<int, string>,
             *     dependency_composer_names: array<int, string>,
             *     missing_dependencies: array<int, string>,
             *     blocked_dependencies: array<int, array{name: string, composer_name: string, reason: ?string}>,
             *     premium_records: array<int, array<string, mixed>>,
             *     selected_count: int,
             *     install_count: int,
             *     total_cents: int,
             *     total_label: string,
             *     has_premium_records: bool,
             *     contains_beta: bool,
             *     beta_dependency_composer_names: array<int, string>,
             *     impact_records: array<int, array<string, mixed>>,
             *     can_install: bool
             * } $review */
            $review = $this->resolvedMarketplaceSelectionReview;

            return $review;
        }

        $review = BuildMarketplaceSelectionReviewAction::run(MarketplaceSelectionInputData::make(
            selectedComposerNames: $this->selectedMarketplaceComposerNames,
            lockedKind: $this->lockedKind,
            includeLocalExtensionState: $this->includeLocalExtensionStateForBrowser(),
            canManageExtensions: ExtensionsPage::canManageExtensions(),
        ));

        return $this->resolvedMarketplaceSelectionReview = $review->toComputedArray(
            freeTotalLabel: (string) __('capell-marketplace::marketplace.install.free'),
            unknownExtensionLabel: (string) __('capell-marketplace::marketplace.selection.unknown_extension'),
            failureReasonLabel: $this->marketplaceSelectionFailureReasonLabel(...),
            impactReasonLabel: static fn (string $reasonCode): string => (string) __(
                'capell-marketplace::marketplace.selection.impact_reason_' . $reasonCode,
            ),
        );
    }

    /**
     * @param  array{install_records: array<int, array<string, mixed>>}  $selection
     */
    public function marketplaceSelectionRequiresLicenceKey(array $selection): bool
    {
        return collect($selection['install_records'])
            ->contains(fn (array $record): bool => $this->marketplaceRecordRequiresLicenceKey($record));
    }

    private function authorizeMarketplaceAccess(): void
    {
        abort_unless(MarketplacePage::canAccess(), 403);
    }

    /**
     * @param  array<int, string>  $composerNames
     * @return array<int, int>
     */
    private function activeMarketplaceInstallAttemptIdsFor(array $composerNames): array
    {
        if ($composerNames === []) {
            return [];
        }

        return MarketplaceInstallAttempt::query()
            ->whereIn('composer_name', $composerNames)
            ->whereIn('status', [
                MarketplaceInstallIntentStatus::Queued->value,
                MarketplaceInstallIntentStatus::Running->value,
                MarketplaceInstallIntentStatus::CancelRequested->value,
            ])
            ->latest()
            ->get()
            // One row per package: a retried install leaves older attempts for
            // the same package behind, and showing the operator two pills for
            // one thing they installed once is a lie about what is happening.
            ->unique('composer_name')
            ->map(fn (MarketplaceInstallAttempt $attempt): int => (int) $attempt->getKey())
            ->values()
            ->all();
    }

    private function marketplaceInstallStageLabel(MarketplaceInstallAttempt $attempt): string
    {
        if ($attempt->status === MarketplaceInstallIntentStatus::Succeeded) {
            return (string) __('capell-marketplace::marketplace.progress.stage_completed');
        }

        if (! $attempt->status->isActiveInstallOperation()) {
            return (string) __('capell-marketplace::marketplace.progress.stage_stopped');
        }

        // An attempt whose recorded stage is unrecognised — an older row, or one
        // written by a newer release — is still waiting as far as the operator
        // can tell, so it reads as queued rather than as a broken label.
        $stage = MarketplaceInstallFailureStage::tryFrom((string) $attempt->current_stage)
            ?? MarketplaceInstallFailureStage::Queue;

        return $stage->progressLabel();
    }

    private function marketplaceUpdateActor(): MarketplaceInstallActorData
    {
        $user = auth()->user();

        return $user instanceof Authenticatable
            ? MarketplaceInstallActorData::fromAuthenticatable($user)
            : MarketplaceInstallActorData::system('marketplace-extensions-browser');
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, bool>
     */
    private function themeActivationInstallOption(array $record): array
    {
        if (($record['kind'] ?? null) !== ExtensionKind::Theme->value) {
            return [];
        }

        return [RecordThemeInstallIntentAction::ACTIVATE_AFTER_INSTALL => $this->activateMarketplaceThemesAfterInstall];
    }

    /** @return array<string, mixed> */
    private function availableMarketplaceFilters(): array
    {
        return [
            ...(is_array($this->tableFilters) ? $this->tableFilters : []),
            'installed_status' => [
                'value' => false,
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function currentMarketplaceRecordsByComposerName(): array
    {
        return collect(resolve(MarketplaceCatalogueRecordProvider::class)->records(
            search: $this->tableSearch,
            filters: $this->availableMarketplaceFilters(),
            lockedKind: $this->lockedKind,
            includeLocalExtensionState: $this->includeLocalExtensionStateForBrowser(),
        ))
            ->mapWithKeys(function (array $record): array {
                $composerName = $this->recordComposerName($record);

                return $composerName === null ? [] : [$composerName => $record];
            })
            ->all();
    }

    private function includeLocalExtensionStateForBrowser(): bool
    {
        return $this->includeLocalExtensionState && ExtensionsPage::canAccess();
    }

    /** @return array<int, string> */
    private function normalizedSelectedMarketplaceComposerNames(): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                fn (mixed $composerName): ?string => is_string($composerName) && trim($composerName) !== '' ? trim($composerName) : null,
                $this->selectedMarketplaceComposerNames,
            ),
            is_string(...),
        )));
    }

    /** @param array<string, mixed> $record */
    private function marketplaceRecordIsSelectable(array $record): bool
    {
        return $this->marketplaceSelectionRecord($record)->isSelectable();
    }

    /** @param array<string, mixed> $record */
    private function marketplaceRecordSelectionBlockReason(array $record): ?string
    {
        $failureReasonCode = $this->marketplaceSelectionRecord($record)->failureReasonCode;

        return $failureReasonCode !== null
            ? $this->marketplaceSelectionFailureReasonLabel($failureReasonCode)
            : null;
    }

    /** @param array<string, mixed> $record */
    private function marketplaceSelectionRecord(array $record): MarketplaceSelectionRecordData
    {
        return resolve(BuildMarketplaceSelectionReviewAction::class)->record(
            payload: $record,
            canManageExtensions: ExtensionsPage::canManageExtensions(),
        );
    }

    private function marketplaceSelectionFailureReasonLabel(string $reasonCode): string
    {
        return match ($reasonCode) {
            MarketplaceSelectionRecordData::FAILURE_INSTALLED,
            MarketplaceSelectionRecordData::FAILURE_INSTALL_IN_PROGRESS,
            MarketplaceSelectionRecordData::FAILURE_INCOMPATIBLE,
            MarketplaceSelectionRecordData::FAILURE_PERMISSION => (string) __(
                'capell-marketplace::marketplace.selection.blocked.' . $reasonCode,
            ),
            MarketplaceSelectionRecordData::FAILURE_UNAVAILABLE => (string) __(
                'capell-marketplace::marketplace.install.tooltip',
            ),
            default => (string) __(
                'capell-marketplace::marketplace.install.blocked.' . $reasonCode . '.tooltip',
            ),
        };
    }

    /**
     * @param  array{
     *     premium_records: array<int, array<string, mixed>>,
     *     install_records: array<int, array<string, mixed>>,
     *     missing_dependencies: array<int, string>,
     *     blocked_dependencies: array<int, array{name: string, composer_name: string, reason: ?string}>,
     *     dependency_composer_names: array<int, string>
     * }  $selection
     */
    private function marketplaceSelectionNeedsHostedFlow(array $selection): bool
    {
        foreach ($selection['premium_records'] as $record) {
            if ((bool) ($record['install_authorized'] ?? false)) {
                continue;
            }

            if ($this->marketplaceRecordRequiresLicenceKey($record) && filled($this->marketplaceLicenseKey)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @param array<string, mixed> $record */
    private function marketplaceRecordRequiresLicenceKey(array $record): bool
    {
        return in_array(MarketplaceInstallState::ActivationRequired->value, [
            $record['marketplace_install_state'] ?? null,
            $record['install_state'] ?? null,
            $record['server_install_state'] ?? null,
            data_get($record, 'install_eligibility_policy.state'),
        ], true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array<string, mixed>>
     */
    private function marketplaceInstallFlowSelections(array $records): array
    {
        return array_values(array_map(fn (array $record): array => [
            'slug' => $this->recordSlug($record),
            'composer_name' => $this->recordComposerName($record),
            'name' => $this->recordName($record),
            'kind' => is_string($record['kind'] ?? null) ? $record['kind'] : 'tool',
            'price_cents' => is_numeric($record['price_cents'] ?? null) ? (int) $record['price_cents'] : 0,
            'install_authorized' => (bool) ($record['install_authorized'] ?? false),
            'install_eligibility' => is_array($record['install_eligibility_policy'] ?? null) ? $record['install_eligibility_policy'] : [],
        ], $records));
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function marketplaceSelectionFallbackPurchaseUrl(array $records): ?string
    {
        foreach ($records as $record) {
            $purchaseUrl = $record['purchase_url'] ?? null;

            if (is_string($purchaseUrl) && $purchaseUrl !== '') {
                return $purchaseUrl;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function recordComposerName(array $record): ?string
    {
        return is_string($record['composer_name'] ?? null) && $record['composer_name'] !== ''
            ? $record['composer_name']
            : null;
    }

    /** @param array<string, mixed> $record */
    private function recordSlug(array $record): string
    {
        return is_string($record['slug'] ?? null) ? $record['slug'] : '';
    }

    /** @param array<string, mixed> $record */
    private function recordName(array $record): string
    {
        return is_string($record['name'] ?? null) && $record['name'] !== ''
            ? $record['name']
            : (string) __('capell-marketplace::marketplace.selection.unknown_extension');
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function defaultMarketplaceInstallOptions(array $records): array
    {
        $defaults = [];

        foreach ($records as $record) {
            foreach ($this->recordInstallOptions($record) as $option) {
                $key = $this->installOptionKey($option);
                if ($key === null) {
                    continue;
                }

                if (array_key_exists($key, $defaults)) {
                    continue;
                }

                $defaults[$key] = match ($this->installOptionType($option)) {
                    'checkbox', 'toggle', 'boolean' => (bool) ($option['default'] ?? false),
                    default => $option['default'] ?? null,
                };
            }
        }

        return $defaults;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function selectedMarketplaceInstallOptionsForRecords(array $records): array
    {
        $allowedKeys = [];

        foreach ($records as $record) {
            foreach ($this->recordInstallOptions($record) as $option) {
                $key = $this->installOptionKey($option);

                if ($key !== null) {
                    $allowedKeys[$key] = true;
                }
            }
        }

        return array_intersect_key($this->selectedMarketplaceInstallOptions, $allowedKeys);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, array<string, mixed>>
     */
    private function selectedMarketplaceInstallOptionsByRecord(array $records): array
    {
        $options = [];

        foreach ($records as $record) {
            $recordOptions = $this->selectedMarketplaceInstallOptionsForRecords([$record]);

            if ($recordOptions === []) {
                continue;
            }

            $composerName = $this->recordComposerName($record);
            if ($composerName !== null) {
                $options[$composerName] = $recordOptions;
            }

            $slug = $this->recordSlug($record);
            if ($slug !== '') {
                $options[$slug] = $recordOptions;
            }
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<int, array<string, mixed>>
     */
    private function recordInstallOptions(array $record): array
    {
        $options = $record['install_options'] ?? [];

        if (! is_array($options)) {
            return [];
        }

        return array_values(array_filter(
            $options,
            is_array(...),
        ));
    }

    /** @param array<string, mixed> $option */
    private function installOptionKey(array $option): ?string
    {
        return is_string($option['key'] ?? null) && $option['key'] !== ''
            ? $option['key']
            : null;
    }

    /** @param array<string, mixed> $option */
    private function installOptionType(array $option): string
    {
        return is_string($option['type'] ?? null) && $option['type'] !== ''
            ? $option['type']
            : 'checkbox';
    }
}
