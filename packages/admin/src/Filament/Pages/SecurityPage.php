<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Support\Security\SecurityLabels;
use Capell\Core\Actions\Security\ApplyApprovedSecurityRemediationsAction;
use Capell\Core\Actions\Security\ApproveSecurityRemediationAction;
use Capell\Core\Actions\Security\DismissSecurityWeaknessFindingAction;
use Capell\Core\Actions\Security\ReopenSecurityWeaknessFindingAction;
use Capell\Core\Actions\Security\RevokeSecurityRemediationApprovalAction;
use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Contracts\Security\SecurityWeaknessAnalyser;
use Capell\Core\Enums\Security\SecurityFindingSource;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Models\SecurityWeaknessFinding;
use Capell\Core\Support\Security\NullSecurityWeaknessAnalyser;
use Capell\Core\Support\Security\SecurityWeaknessRegistry;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Override;

final class SecurityPage extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    public const string MANAGE_PERMISSION = 'Manage:SecurityPage';

    public bool $showAllHistory = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $slug = 'security';

    protected string $view = 'capell-admin::filament.pages.security';

    /** @var array<string, SecurityRemediationApproval>|null */
    private ?array $approvalCache = null;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::security.title');
    }

    #[Override]
    public static function getNavigationGroup(): string
    {
        return (string) __('capell-admin::navigation.group_security');
    }

    #[Override]
    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->can('View:SecurityPage') ?? false;
    }

    public static function canManage(): bool
    {
        $user = Filament::auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->can('View:SecurityPage') && $user->can(self::MANAGE_PERMISSION);
    }

    #[Override]
    public function getTitle(): string
    {
        return (string) __('capell-admin::security.title');
    }

    public function table(Table $table): Table
    {
        return $table->query(SecurityWeaknessFinding::query()->orderByRaw("case severity when 'critical' then 4 when 'high' then 3 when 'medium' then 2 else 1 end desc")->latest('last_detected_at'))
            ->emptyStateHeading(fn (): string => SecurityWeaknessFinding::query()->exists() ? (string) __('capell-admin::security.no_matching_findings') : (string) __('capell-admin::security.no_findings'))
            ->columns([
                TextColumn::make('severity')->label(__('capell-admin::security.severity'))->badge()->formatStateUsing(fn (SecurityWeaknessSeverity $state): string => SecurityLabels::severity($state)),
                TextColumn::make('title')->label(__('capell-admin::security.finding'))->description(fn (SecurityWeaknessFinding $record): string => $record->weakness_id . ($record->source === SecurityFindingSource::Analyser ? ' — ' . __('capell-admin::security.advice_only') : '')),
                TextColumn::make('source')->label(__('capell-admin::security.source'))->badge()->formatStateUsing(fn (SecurityFindingSource $state): string => SecurityLabels::source($state)),
                TextColumn::make('composer_name')->label(__('capell-admin::security.package'))->placeholder('—'),
                TextColumn::make('first_detected_at')->label(__('capell-admin::security.first_seen'))->dateTime(),
                TextColumn::make('last_detected_at')->label(__('capell-admin::security.last_seen'))->dateTime(),
                TextColumn::make('status')->label(__('capell-admin::security.status'))->badge()->formatStateUsing(fn (SecurityFindingStatus $state): string => SecurityLabels::status($state)),
                TextColumn::make('automatic_fix')->label(__('capell-admin::security.automatic_fix'))->state(fn (SecurityWeaknessFinding $record): string => $this->fixState($record)),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('capell-admin::security.status'))->options(['active' => __('capell-admin::security.active'), ...SecurityLabels::statusOptions()])->default('active')
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'active' => $query->whereIn('status', [SecurityFindingStatus::Open->value, SecurityFindingStatus::Remediated->value]),
                        null, '' => $query,
                        default => $query->where('status', $data['value']),
                    }),
                SelectFilter::make('severity')->label(__('capell-admin::security.severity'))->options(SecurityLabels::severityOptions()),
                SelectFilter::make('source')->label(__('capell-admin::security.source'))->options(SecurityLabels::sourceOptions()),
            ])
            ->recordActions([
                Action::make('evidence')->label(__('capell-admin::security.view_evidence'))->modalSubmitAction(false)->modalCancelActionLabel(__('capell-admin::security.close'))
                    ->modalDescription(fn (SecurityWeaknessFinding $record): string => $this->evidenceDescription($record)),
                Action::make('approve')->label(__('capell-admin::security.approve'))->requiresConfirmation()->disabled(fn (): bool => ! self::canManage())
                    ->visible(fn (SecurityWeaknessFinding $record): bool => $this->canApprove($record))
                    ->modalDescription(fn (SecurityWeaknessFinding $record): string => $this->approvalDescription($record))
                    ->action(function (SecurityWeaknessFinding $record): void {
                        $user = $this->manager();
                        abort_unless($this->canApprove($record), 403);
                        ApproveSecurityRemediationAction::run($record->weakness_id, $user);
                        $this->approvalCache = null;
                    }),
                Action::make('revoke')->label(__('capell-admin::security.revoke'))->requiresConfirmation()->disabled(fn (): bool => ! self::canManage())
                    ->visible(fn (SecurityWeaknessFinding $record): bool => ($this->approvals()[$record->weakness_id] ?? null)?->isActive() ?? false)
                    ->action(function (SecurityWeaknessFinding $record): void {
                        RevokeSecurityRemediationApprovalAction::run($record->weakness_id, $this->manager());
                        $this->approvalCache = null;
                    }),
                Action::make('fixNow')->label(__('capell-admin::security.fix_now'))->requiresConfirmation()->disabled(fn (): bool => ! self::canManage())
                    ->modalDescription(fn (SecurityWeaknessFinding $record): string => $this->fixNowDescription($record))
                    ->visible(fn (SecurityWeaknessFinding $record): bool => $record->source->mayRemediateAutomatically() && $record->status === SecurityFindingStatus::Open && $record->remediable && resolve(SecurityWeaknessRegistry::class)->remediation($record->weakness_id) !== null && (($this->approvals()[$record->weakness_id] ?? null)?->isActive() ?? false))
                    ->action(function (SecurityWeaknessFinding $record): void {
                        $this->assertManager();
                        abort_unless($record->source->mayRemediateAutomatically() && $record->status === SecurityFindingStatus::Open && $record->remediable && resolve(SecurityWeaknessRegistry::class)->remediation($record->weakness_id) !== null && (($this->approvals()[$record->weakness_id] ?? null)?->isActive() ?? false), 403);
                        $this->applyFix($record->weakness_id);
                    }),
                Action::make('dismiss')->label(__('capell-admin::security.dismiss'))->disabled(fn (): bool => ! self::canManage())
                    ->visible(fn (SecurityWeaknessFinding $record): bool => $record->status->isActive())
                    ->schema([Textarea::make('note')->label(__('capell-admin::security.note'))])
                    ->action(function (SecurityWeaknessFinding $record, array $data): void {
                        DismissSecurityWeaknessFindingAction::run($record, $this->manager(), $data['note'] ?? null);
                    }),
                Action::make('reopen')->label(__('capell-admin::security.reopen'))->disabled(fn (): bool => ! self::canManage())
                    ->visible(fn (SecurityWeaknessFinding $record): bool => $record->status === SecurityFindingStatus::Dismissed)
                    ->action(function (SecurityWeaknessFinding $record): void {
                        $this->assertManager();
                        abort_unless($record->status === SecurityFindingStatus::Dismissed, 403);
                        ReopenSecurityWeaknessFindingAction::run($record);
                    }),
            ]);
    }

    public function revokeApprovalAction(): Action
    {
        return Action::make('revokeApproval')->label(__('capell-admin::security.revoke'))->requiresConfirmation()->disabled(fn (): bool => ! self::canManage())
            ->action(function (array $arguments): void {
                $user = $this->manager();
                $id = $arguments['weakness'] ?? null;
                abort_unless(is_string($id), 400);
                RevokeSecurityRemediationApprovalAction::run($id, $user);
                $this->approvalCache = null;
            });
    }

    /** @return array<string, mixed> */
    #[Override]
    protected function getViewData(): array
    {
        $counts = SecurityWeaknessFinding::query()->where('status', SecurityFindingStatus::Open->value)
            ->selectRaw('severity, count(*) as total')->groupBy('severity')->pluck('total', 'severity')->all();
        $analyser = app()->bound(SecurityWeaknessAnalyser::class) ? resolve(SecurityWeaknessAnalyser::class) : new NullSecurityWeaknessAnalyser;
        $registry = resolve(SecurityWeaknessRegistry::class);

        return [
            'counts' => $counts,
            'severityLabels' => SecurityLabels::severityOptions(),
            'lastSeen' => SecurityWeaknessFinding::query()->max('last_detected_at'),
            'analyserName' => $analyser->name(),
            'analyserAvailable' => $analyser->isAvailable(),
            'canManage' => self::canManage(),
            'approvals' => SecurityRemediationApproval::query()->latest('approved_at')->get()->map(fn (SecurityRemediationApproval $approval): array => [
                'weakness' => $approval->weakness_id,
                'description' => $registry->remediation($approval->weakness_id)?->describe() ?? __('capell-admin::security.no_longer_available'),
                'approver' => $approval->approved_by,
                'approvedAt' => $approval->approved_at->toDateTimeString(),
                'note' => $approval->note,
                'active' => $approval->isActive(),
                'revokedAt' => $approval->revoked_at?->toDateTimeString(),
            ])->all(),
            'runs' => SecurityRemediationRun::query()->latest('started_at')->latest('id')->unless($this->showAllHistory, fn (Builder $query): Builder => $query->limit(50))->get()->map(fn (SecurityRemediationRun $run): array => [
                'when' => $run->started_at->toDateTimeString(), 'weakness' => $run->weakness_id,
                'actor' => $run->actor, 'outcome' => SecurityLabels::outcome($run->outcome), 'message' => $run->message,
                'before' => $run->before, 'after' => $run->after,
            ])->all(),
        ];
    }

    /** @return array<int, Action> */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('scan')->label(__('capell-admin::security.scan'))->disabled(! self::canManage())
                ->action(function (): void {
                    $this->assertManager();
                    $result = RunSecurityWeaknessScanAction::run(includeAnalyser: true);
                    Notification::make()->title(__('capell-admin::security.scan_result', ['new' => $result->newFindings, 'reopened' => $result->reopenedFindings, 'resolved' => $result->resolvedFindings]))
                        ->body($result->failures === [] ? null : (string) __('capell-admin::security.scan_failures', ['count' => count($result->failures)]))
                        ->status($result->failures === [] ? 'success' : 'warning')->send();
                }),
            Action::make('fixApproved')->label(__('capell-admin::security.fix_approved'))->disabled(! self::canManage())
                ->requiresConfirmation()->action(fn () => $this->applyFix()),
        ];
    }

    private function applyFix(?string $weakness = null): void
    {
        $user = $this->manager();
        $actor = 'user:' . $user->getAuthIdentifier();
        // Fixes apply only to findings the scan taken now confirms, never to stale stored findings.
        $scan = RunSecurityWeaknessScanAction::run(false, $actor);
        $result = ApplyApprovedSecurityRemediationsAction::run(
            $actor,
            $scan->scanId ?? '',
            false,
            $weakness,
            $scan->scannedAt,
            array_values(array_map(static fn (string $failure): string => explode(': ', substr($failure, 7), 2)[0], array_filter($scan->failures, static fn (string $failure): bool => str_starts_with($failure, 'check: ')))),
        );
        Notification::make()->title(__('capell-admin::security.fix_result', ['applied' => $result->applied, 'failed' => $result->failed, 'skipped' => count($result->skipped)]))->status($result->failed > 0 ? 'warning' : 'success')->send();
    }

    private function assertManager(): void
    {
        abort_unless(self::canManage(), 403);
    }

    private function manager(): Authenticatable
    {
        $this->assertManager();
        $user = auth()->user();
        abort_unless($user instanceof Authenticatable, 403);

        return $user;
    }

    /** @return array<string, SecurityRemediationApproval> */
    private function approvals(): array
    {
        return $this->approvalCache ??= SecurityRemediationApproval::query()->get()->keyBy('weakness_id')->all();
    }

    private function canApprove(SecurityWeaknessFinding $finding): bool
    {
        return $finding->status->isActive() && $finding->remediable && $finding->source->mayRemediateAutomatically()
            && resolve(SecurityWeaknessRegistry::class)->remediation($finding->weakness_id) !== null
            && ! (($this->approvals()[$finding->weakness_id] ?? null)?->isActive() ?? false);
    }

    private function fixState(SecurityWeaknessFinding $finding): string
    {
        $approval = $this->approvals()[$finding->weakness_id] ?? null;
        if ($finding->source->mayRemediateAutomatically() && $finding->remediable && resolve(SecurityWeaknessRegistry::class)->remediation($finding->weakness_id) !== null) {
            return $approval?->isActive() ? (string) __('capell-admin::security.approved_by', ['user' => $approval->approved_by ?? '—', 'date' => $approval->approved_at->toDateTimeString()]) : (string) __('capell-admin::security.available');
        }

        return (string) __('capell-admin::security.not_available');
    }

    private function approvalDescription(SecurityWeaknessFinding $finding): string
    {
        $remediation = resolve(SecurityWeaknessRegistry::class)->remediation($finding->weakness_id);

        $scheduled = config('capell.security.scan.scheduled', true) === true;
        $fixScheduled = config('capell.security.scan.fix_approved', true) === true;

        return implode("\n\n", [
            $remediation?->describe() ?? (string) __('capell-admin::security.no_longer_available'),
            (string) __('capell-admin::security.approval_scope', ['weakness' => $finding->weakness_id]),
            $remediation?->isReversible() === true ? (string) __('capell-admin::security.reversible') : (string) __('capell-admin::security.not_reversible'),
            $scheduled && $fixScheduled ? (string) __('capell-admin::security.approval_schedule_enabled') : (string) __('capell-admin::security.approval_schedule_disabled'),
        ]);
    }

    private function fixNowDescription(SecurityWeaknessFinding $finding): string
    {
        $count = SecurityWeaknessFinding::query()->where('weakness_id', $finding->weakness_id)
            ->where('status', SecurityFindingStatus::Open->value)->count();

        return implode("\n\n", [
            trans_choice('capell-admin::security.fix_now_scope', $count, ['weakness' => $finding->weakness_id, 'count' => $count]),
            $this->approvalDescription($finding),
        ]);
    }

    private function evidenceDescription(SecurityWeaknessFinding $finding): string
    {
        $evidence = json_encode($finding->evidence ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $parts = [
            $finding->summary,
            $finding->source === SecurityFindingSource::Analyser ? (string) __('capell-admin::security.advice_only') : null,
            $evidence === false ? null : $evidence,
            $finding->recommended_action,
            $finding->dismissal_note === null ? null : (string) __('capell-admin::security.dismissal_note', ['note' => $finding->dismissal_note]),
        ];

        return implode("\n\n", array_filter($parts, static fn (?string $part): bool => $part !== null && $part !== ''));
    }
}
