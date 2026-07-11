<?php

declare(strict_types=1);

namespace Capell\Marketplace\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Filament\Pages\ExtensionsPage;
use Capell\Marketplace\Actions\BuildMarketplaceInstallDiagnosticBundleAction;
use Capell\Marketplace\Actions\CancelMarketplaceInstallAttemptAction;
use Capell\Marketplace\Actions\ResolveMarketplaceInstallOperationAction;
use Capell\Marketplace\Actions\RetryMarketplaceInstallAttemptAction;
use Capell\Marketplace\Enums\MarketplaceInstallFailureType;
use Capell\Marketplace\Enums\MarketplaceInstallIntentStatus;
use Capell\Marketplace\Models\MarketplaceInstallAttempt;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Override;

final class MarketplacePackageOperationsPage extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    private const string TAB_ACTIVE = 'active';

    private const string TAB_FAILED = 'failed';

    private const string TAB_SUCCEEDED = 'succeeded';

    private const string TAB_RESOLVED = 'resolved';

    private const string TAB_ALL = 'all';

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    #[Url(as: 'operation')]
    public ?int $selectedOperationId = null;

    public ?string $diagnosticBundle = null;

    /** @var array<string, Tab> */
    protected array $cachedTabs;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::QueueList;

    protected static ?string $slug = 'extensions/package-operations';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'capell-marketplace::filament.pages.package-operations';

    #[Override]
    public static function canAccess(): bool
    {
        return ExtensionsPage::canAccess();
    }

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-marketplace::marketplace.operations.page_title');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return (string) __('capell-admin::navigation.group_system');
    }

    #[Override]
    public function getTitle(): string
    {
        return (string) __('capell-marketplace::marketplace.operations.page_title');
    }

    #[Override]
    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function mount(): void
    {
        $this->loadDefaultActiveTab();

        if (! array_key_exists($this->activeTab, $this->getTabs())) {
            $this->activeTab = self::TAB_ACTIVE;
        }
    }

    #[Override]
    public function getBreadcrumbs(): array
    {
        return [
            ExtensionsPage::getUrl() => ExtensionsPage::getNavigationLabel(),
            MarketplacePage::getUrl() => MarketplacePage::getNavigationLabel(),
            self::getNavigationLabel(),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            self::TAB_ACTIVE => Tab::make(__('capell-marketplace::marketplace.operations.tab_active'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    MarketplaceInstallIntentStatus::Queued->value,
                    MarketplaceInstallIntentStatus::Running->value,
                    MarketplaceInstallIntentStatus::CancelRequested->value,
                ])->whereNull('resolved_at')),
            self::TAB_FAILED => Tab::make(__('capell-marketplace::marketplace.operations.tab_failed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('status', [
                    MarketplaceInstallIntentStatus::Failed->value,
                    MarketplaceInstallIntentStatus::TimedOut->value,
                    MarketplaceInstallIntentStatus::Cancelled->value,
                ])->whereNull('resolved_at')),
            self::TAB_SUCCEEDED => Tab::make(__('capell-marketplace::marketplace.operations.tab_succeeded'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', MarketplaceInstallIntentStatus::Succeeded->value)),
            self::TAB_RESOLVED => Tab::make(__('capell-marketplace::marketplace.operations.tab_resolved'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('resolved_at')),
            self::TAB_ALL => Tab::make(__('capell-marketplace::marketplace.operations.tab_all')),
        ];
    }

    /** @return array<string, Tab> */
    public function getCachedTabs(): array
    {
        return $this->cachedTabs ??= collect($this->getTabs())
            ->map(fn (Tab $tab, string $key): Tab => $tab->hasCustomLabel() ? $tab : $tab->label($this->generateTabLabel($key)))
            ->all();
    }

    public function getDefaultActiveTab(): ?string
    {
        return array_key_first($this->getCachedTabs());
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();

        $this->cachedDefaultTableColumnState = null;

        $this->applyTableColumnManager();
    }

    public function generateTabLabel(string $key): string
    {
        return (string) str($key)
            ->replace(['_', '-'], ' ')
            ->ucfirst();
    }

    public function getTabsContentComponent(): Tabs
    {
        $tabs = $this->getCachedTabs();

        return Tabs::make()
            ->key('resourceTabs')
            ->livewireProperty('activeTab')
            ->contained(false)
            ->tabs($tabs)
            ->hidden($tabs === []);
    }

    #[Override]
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => MarketplaceInstallAttempt::query()->with('events'))
            ->modifyQueryUsing($this->modifyQueryWithActiveTab(...))
            ->queryStringIdentifier('marketplace-package-operations')
            ->heading(null)
            ->columns([
                TextColumn::make('extension_name')
                    ->label(__('capell-marketplace::marketplace.operations.extension'))
                    ->description(fn (MarketplaceInstallAttempt $record): string => $record->composer_name)
                    ->searchable(['extension_name', 'composer_name'])
                    ->sortable()
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('capell-marketplace::marketplace.operations.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('failure_type')
                    ->label(__('capell-marketplace::marketplace.operations.failure_type'))
                    ->placeholder('-')
                    ->badge()
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('failure_stage')
                    ->label(__('capell-marketplace::marketplace.operations.failure_stage'))
                    ->placeholder('-')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('user_email')
                    ->label(__('capell-marketplace::marketplace.operations.requester'))
                    ->placeholder('-')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('deployment_reference')
                    ->label(__('capell-marketplace::marketplace.operations.deployment_reference'))
                    ->state(fn (MarketplaceInstallAttempt $record): ?string => data_get($record->deployment, 'reference'))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label(__('capell-marketplace::marketplace.operations.updated_at'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('capell-marketplace::marketplace.operations.status'))
                    ->options($this->statusOptions()),
                SelectFilter::make('failure_type')
                    ->label(__('capell-marketplace::marketplace.operations.failure_type'))
                    ->options($this->failureTypeOptions()),
            ])
            ->recordAction('viewOperation')
            ->recordActions([
                Action::make('viewOperation')
                    ->label(__('capell-marketplace::marketplace.operations.view'))
                    ->icon(Heroicon::OutlinedEye)
                    ->action(function (MarketplaceInstallAttempt $record): void {
                        $this->selectOperation((int) $record->getKey());
                    }),
                Action::make('retryOperation')
                    ->label(__('capell-marketplace::marketplace.operations.retry'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (MarketplaceInstallAttempt $record): bool => $this->canManagePackageOperations() && $this->canRetry($record))
                    ->action(function (MarketplaceInstallAttempt $record): void {
                        $this->retry((int) $record->getKey());
                    }),
                Action::make('copyDiagnostics')
                    ->label(__('capell-marketplace::marketplace.operations.copy_diagnostics'))
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->color('gray')
                    ->visible(fn (): bool => $this->canManagePackageOperations())
                    ->action(function (MarketplaceInstallAttempt $record): void {
                        $this->copyDiagnostics((int) $record->getKey());
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading(__('capell-marketplace::marketplace.operations.empty_tab'))
            ->searchPlaceholder(__('capell-marketplace::marketplace.operations.search_placeholder'));
    }

    public function selectedOperation(): ?MarketplaceInstallAttempt
    {
        if ($this->selectedOperationId === null) {
            return MarketplaceInstallAttempt::query()
                ->with('events')
                ->latest('updated_at')
                ->first();
        }

        return MarketplaceInstallAttempt::query()
            ->with('events')
            ->find($this->selectedOperationId);
    }

    public function selectOperation(int $operationId): void
    {
        $this->selectedOperationId = $operationId;
        $this->diagnosticBundle = null;
    }

    public function retry(int $operationId): void
    {
        if (! $this->canManagePackageOperations()) {
            return;
        }

        $attempt = MarketplaceInstallAttempt::query()->find($operationId);

        if (! $attempt instanceof MarketplaceInstallAttempt || ! $this->canRetry($attempt)) {
            return;
        }

        $retry = RetryMarketplaceInstallAttemptAction::run($attempt, auth()->user());

        $this->activeTab = self::TAB_ACTIVE;
        $this->selectedOperationId = (int) $retry->getKey();

        Notification::make()
            ->success()
            ->title((string) __('capell-marketplace::marketplace.operations.retry_queued'))
            ->body((string) __('capell-marketplace::marketplace.operations.retry_queued_body'))
            ->send();
    }

    public function cancel(int $operationId): void
    {
        if (! $this->canManagePackageOperations()) {
            return;
        }

        $attempt = MarketplaceInstallAttempt::query()->find($operationId);

        if (! $attempt instanceof MarketplaceInstallAttempt || ! $this->canCancel($attempt)) {
            return;
        }

        CancelMarketplaceInstallAttemptAction::run($attempt);
        $this->selectedOperationId = $operationId;
    }

    public function markResolved(int $operationId): void
    {
        if (! $this->canManagePackageOperations()) {
            return;
        }

        $attempt = MarketplaceInstallAttempt::query()->find($operationId);

        if (! $attempt instanceof MarketplaceInstallAttempt || ! $this->canMarkResolved($attempt)) {
            return;
        }

        ResolveMarketplaceInstallOperationAction::run($attempt);
        $this->activeTab = self::TAB_RESOLVED;
        $this->selectedOperationId = $operationId;
    }

    public function copyDiagnostics(int $operationId): void
    {
        if (! $this->canManagePackageOperations()) {
            return;
        }

        $attempt = MarketplaceInstallAttempt::query()->with('events')->find($operationId);

        if (! $attempt instanceof MarketplaceInstallAttempt) {
            return;
        }

        $this->diagnosticBundle = BuildMarketplaceInstallDiagnosticBundleAction::run($attempt);
    }

    public function marketplaceUrl(): string
    {
        return MarketplacePage::getUrl();
    }

    public function extensionsUrl(): string
    {
        return ExtensionsPage::getUrl();
    }

    public function canRetry(MarketplaceInstallAttempt $attempt): bool
    {
        if (in_array($attempt->status, [
            MarketplaceInstallIntentStatus::Failed,
            MarketplaceInstallIntentStatus::TimedOut,
        ], true)) {
            return true;
        }

        return $attempt->status === MarketplaceInstallIntentStatus::Cancelled
            && $attempt->failure_type === MarketplaceInstallFailureType::CancelledAfterComposer->value;
    }

    public function canManagePackageOperations(): bool
    {
        return ExtensionsPage::canManageExtensions();
    }

    public function canCancel(MarketplaceInstallAttempt $attempt): bool
    {
        return in_array($attempt->status, [
            MarketplaceInstallIntentStatus::Queued,
            MarketplaceInstallIntentStatus::Running,
        ], true);
    }

    public function canMarkResolved(MarketplaceInstallAttempt $attempt): bool
    {
        return $attempt->resolved_at === null
            && in_array($attempt->status, [
                MarketplaceInstallIntentStatus::Failed,
                MarketplaceInstallIntentStatus::TimedOut,
                MarketplaceInstallIntentStatus::Succeeded,
                MarketplaceInstallIntentStatus::Cancelled,
            ], true);
    }

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('extensions')
                ->label(ExtensionsPage::getNavigationLabel())
                ->icon(ExtensionsPage::getNavigationIcon())
                ->color('gray')
                ->url(ExtensionsPage::getUrl()),
            Action::make('marketplace')
                ->label(MarketplacePage::getNavigationLabel())
                ->icon(MarketplacePage::getNavigationIcon())
                ->color('gray')
                ->url(MarketplacePage::getUrl()),
        ];
    }

    protected function loadDefaultActiveTab(): void
    {
        if (filled($this->activeTab)) {
            return;
        }

        $this->activeTab = $this->getDefaultActiveTab();
    }

    protected function modifyQueryWithActiveTab(Builder $query, bool $isResolvingRecord = false): Builder
    {
        if (blank($this->activeTab)) {
            return $query;
        }

        $tabs = $this->getCachedTabs();

        if (! array_key_exists($this->activeTab, $tabs)) {
            return $query;
        }

        $tab = $tabs[$this->activeTab];

        if ($isResolvingRecord && $tab->shouldExcludeQueryWhenResolvingRecord()) {
            return $query;
        }

        return $tab->modifyQuery($query);
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        return collect(MarketplaceInstallIntentStatus::cases())
            ->mapWithKeys(fn (MarketplaceInstallIntentStatus $status): array => [
                $status->value => str($status->value)->replace('_', ' ')->title()->toString(),
            ])
            ->all();
    }

    /** @return array<string, string> */
    private function failureTypeOptions(): array
    {
        return collect(MarketplaceInstallFailureType::cases())
            ->mapWithKeys(fn (MarketplaceInstallFailureType $failureType): array => [
                $failureType->value => str($failureType->value)->replace('_', ' ')->title()->toString(),
            ])
            ->all();
    }
}
