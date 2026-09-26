<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Actions\CheckForUpdatesAction;
use Capell\Admin\Actions\Upgrade\BuildUpgradeSummaryAction;
use Capell\Admin\Actions\Upgrade\QueueCapellUpgradeAction;
use Capell\Admin\Actions\Upgrade\ReadLatestUpgradeSnapshotAction;
use Capell\Admin\Data\Upgrade\UpgradeAdvisorySnapshotData;
use Capell\Admin\Data\Upgrade\UpgradeNoticeData;
use Capell\Admin\Enums\CapellPermission;
use Capell\Core\Actions\Upgrade\BuildUpgradeReadinessReportAction;
use Capell\Core\Data\Upgrade\UpgradeReadinessReportData;
use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Models\UpgradeRunEvent;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Override;
use Throwable;

/**
 * @phpstan-type Notice array<string, mixed>
 */
class UpgradePage extends Page
{
    use HasPageShield;

    private const string UPDATE_NOTICE_DISMISSALS_TABLE = 'marketplace_update_notice_dismissals';

    public ?string $lastOutput = null;

    public ?int $lastExitCode = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowUp;

    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::CloudArrowUp;

    protected static ?string $slug = 'upgrade';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 1;

    protected string $view = 'capell-admin::filament.pages.upgrade';

    private ?UpgradeReadinessReportData $readinessReport = null;

    private ?UpgradeRun $currentOrLastUpgradeRun = null;

    /** @var array<int, UpgradeRunEvent>|null */
    private ?array $recentUpgradeRunEvents = null;

    /** @var array<string, true>|null */
    private ?array $dismissedNoticeIds = null;

    #[Override]
    public static function getNavigationLabel(): string
    {
        return (string) __('capell-admin::navigation.upgrade');
    }

    #[Override]
    public static function getNavigationGroup(): ?string
    {
        return (string) __('capell-admin::navigation.group_system');
    }

    #[Override]
    public static function getNavigationBadge(): ?string
    {
        return BuildUpgradeSummaryAction::run()->navigationBadge;
    }

    #[Override]
    public static function getNavigationBadgeColor(): string|array|null
    {
        return BuildUpgradeSummaryAction::run()->navigationBadgeColor;
    }

    #[Override]
    public function getTitle(): string|Htmlable
    {
        return __('capell-admin::heading.upgrade');
    }

    #[Override]
    public function getSubheading(): string|Htmlable|null
    {
        return __('capell-admin::generic.upgrade_info');
    }

    public function latestAdvisorySnapshot(): ?UpgradeAdvisorySnapshotData
    {
        return ReadLatestUpgradeSnapshotAction::run();
    }

    public function installedCapellVersion(): string
    {
        $snapshot = $this->latestAdvisorySnapshot();

        return CapellCore::getInstalledPrettyVersion('capell-app/capell')
            ?? ($snapshot instanceof UpgradeAdvisorySnapshotData ? $snapshot->capell_version : null)
            ?? (string) __('capell-admin::generic.unknown');
    }

    public function targetCapellVersion(): string
    {
        $coreNotice = collect($this->updateNotices())
            ->merge($this->securityAdvisories())
            ->merge($this->bugAdvisories())
            ->first(fn (array $notice): bool => in_array('capell-app/capell', $this->noticeComposerNames($notice), true));

        if (is_array($coreNotice)) {
            return $this->noticeRecommendedVersion($coreNotice);
        }

        return (string) __('capell-admin::generic.no_core_update_target');
    }

    public function updateDistanceLabel(): string
    {
        $versionsBehind = collect($this->updateNotices())
            ->map(fn (array $notice): ?int => $this->noticeData($notice)->versionsBehind)
            ->filter(fn (?int $versionsBehind): bool => $versionsBehind !== null)
            ->max();

        if (! is_int($versionsBehind)) {
            return (string) __('capell-admin::generic.update_distance_unknown');
        }

        if ($versionsBehind === 0) {
            return (string) __('capell-admin::generic.update_distance_current');
        }

        return trans_choice('capell-admin::generic.update_distance_behind', $versionsBehind, [
            'count' => $versionsBehind,
        ]);
    }

    /**
     * @return array{security: int, bugfix: int, feature: int, major: int, package: int, total: int}
     */
    public function updateSummaryCounts(): array
    {
        $counts = [
            'security' => count($this->securityAdvisories()),
            'bugfix' => count($this->bugAdvisories()),
            'feature' => 0,
            'major' => 0,
            'package' => count($this->updateNotices()),
            'total' => 0,
        ];

        foreach ($this->updateNotices() as $notice) {
            $type = $this->noticeUpdateType($notice);

            if (array_key_exists($type, $counts)) {
                $counts[$type]++;
            }
        }

        $counts['total'] = $counts['security'] + $counts['bugfix'] + $counts['package'];

        return $counts;
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeUpdateType(array $notice): string
    {
        return $this->noticeData($notice)->type;
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeImpactLabel(array $notice): string
    {
        $noticeData = $this->noticeData($notice);
        $type = $noticeData->type;
        $severity = $noticeData->severity;

        if ($type === 'major' || in_array($severity, ['critical', 'high'], true)) {
            return (string) __('capell-admin::generic.impact_high');
        }

        if ($type === 'feature' || $severity === 'medium') {
            return (string) __('capell-admin::generic.impact_medium');
        }

        return (string) __('capell-admin::generic.impact_low');
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeVersionLine(array $notice): string
    {
        return (string) __('capell-admin::generic.current_to_target_version', [
            'current' => $this->noticeInstalledVersion($notice),
            'target' => $this->noticeRecommendedVersion($notice),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function securityAdvisories(): array
    {
        $snapshot = $this->latestAdvisorySnapshot();

        if (! $snapshot instanceof UpgradeAdvisorySnapshotData) {
            return [];
        }

        $notices = collect($snapshot->advisories)
            ->filter(fn (array $notice): bool => $this->noticeData($notice)->type === 'security')
            ->sortByDesc(fn (array $notice): int => $this->severityWeight($this->noticeData($notice)->severity))
            ->values()
            ->all();

        return $this->visibleNotices($notices);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bugAdvisories(): array
    {
        $snapshot = $this->latestAdvisorySnapshot();

        if (! $snapshot instanceof UpgradeAdvisorySnapshotData) {
            return [];
        }

        $notices = collect($snapshot->advisories)
            ->filter(fn (array $notice): bool => $this->noticeData($notice)->type === 'bugfix')
            ->sortByDesc(fn (array $notice): int => $this->severityWeight($this->noticeData($notice)->severity))
            ->values()
            ->all();

        return $this->visibleNotices($notices);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function updateNotices(): array
    {
        $snapshot = $this->latestAdvisorySnapshot();

        if (! $snapshot instanceof UpgradeAdvisorySnapshotData) {
            return [];
        }

        $notices = collect($snapshot->updates)
            ->values()
            ->all();

        return $this->visibleNotices($notices);
    }

    public function checkForUpdates(): null
    {
        try {
            $wasSuccessful = CheckForUpdatesAction::run() === true;
        } catch (Throwable $throwable) {
            Notification::make('update-check-failed')
                ->danger()
                ->title(__('capell-admin::message.update_check_failed'))
                ->body($throwable->getMessage())
                ->send();

            return null;
        }

        Notification::make($wasSuccessful ? 'update-check-complete' : 'update-check-failed')
            ->status($wasSuccessful ? 'success' : 'danger')
            ->title($wasSuccessful
                ? __('capell-admin::message.update_check_complete')
                : __('capell-admin::message.update_check_failed'))
            ->send();

        return null;
    }

    public function dismissNotice(string $noticeId): null
    {
        $userId = auth()->id();

        if (! is_int($userId)) {
            return null;
        }

        $notice = $this->findNotice($noticeId);

        if ($notice === null) {
            Notification::make('update-notice-dismiss-failed')
                ->danger()
                ->title(__('capell-admin::message.update_notice_dismiss_failed'))
                ->send();

            return null;
        }

        try {
            DB::table(self::UPDATE_NOTICE_DISMISSALS_TABLE)->updateOrInsert(
                [
                    'user_id' => $userId,
                    'notice_id' => $this->noticeId($notice),
                ],
                [
                    'dismissed_until' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        } catch (Throwable $throwable) {
            Notification::make('update-notice-dismiss-failed')
                ->danger()
                ->title(__('capell-admin::message.update_notice_dismiss_failed'))
                ->body($throwable->getMessage())
                ->send();

            return null;
        }

        Notification::make('update-notice-dismissed')
            ->success()
            ->title(__('capell-admin::message.update_notice_dismissed'))
            ->send();

        return null;
    }

    /**
     * @param  Notice  $notice
     * @return list<string>
     */
    public function noticeComposerNames(array $notice): array
    {
        return $this->noticeData($notice)->composerNames;
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeComposerNamesLabel(array $notice): string
    {
        $names = $this->noticeComposerNames($notice);

        if ($names === []) {
            return (string) __('capell-admin::generic.unknown');
        }

        return implode(', ', $names);
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeInstalledVersion(array $notice): string
    {
        return $this->noticeData($notice)->installedVersion
            ?: (string) __('capell-admin::generic.unknown');
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeRecommendedVersion(array $notice): string
    {
        $recommendedVersion = $this->noticeData($notice)->recommendedVersion;

        if ($recommendedVersion !== '') {
            return $recommendedVersion;
        }

        return $this->noticeFixedVersionsLabel($notice);
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeFixedVersionsLabel(array $notice): string
    {
        $fixedVersions = $notice['fixed_versions'] ?? null;

        if (is_string($fixedVersions) && $fixedVersions !== '') {
            return $fixedVersions;
        }

        if (! is_array($fixedVersions)) {
            return (string) __('capell-admin::generic.unknown');
        }

        $values = collect($fixedVersions)
            ->map(fn (mixed $version, int|string $package): ?string => is_string($version) && $version !== ''
                ? (is_string($package) ? $package . ': ' . $version : $version)
                : null)
            ->filter()
            ->values()
            ->all();

        return $values === [] ? (string) __('capell-admin::generic.unknown') : implode(', ', $values);
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeComposerCommand(array $notice): string
    {
        $composerNames = $this->noticeComposerNames($notice);

        if ($composerNames === []) {
            return (string) __('capell-admin::generic.unknown');
        }

        return 'composer update ' . implode(' ', $composerNames);
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeCanBeDismissed(array $notice): bool
    {
        return $this->noticeId($notice) !== ''
            && ! $this->isPersistentSecurityNotice($notice);
    }

    public function manualUpgradeCommand(bool $dryRun = false): string
    {
        $command = 'php artisan capell:upgrade --force --no-clear-cache';

        return $dryRun ? $command . ' --dry-run' : $command;
    }

    public function readinessReport(): UpgradeReadinessReportData
    {
        return $this->readinessReport ??= BuildUpgradeReadinessReportAction::run();
    }

    public function currentOrLastUpgradeRun(): ?UpgradeRun
    {
        if (! Schema::hasTable('capell_upgrade_runs')) {
            return null;
        }

        return $this->currentOrLastUpgradeRun ??= UpgradeRun::query()
            ->latest('created_at')
            ->first();
    }

    /**
     * @return array<int, UpgradeRunEvent>
     */
    public function recentUpgradeRunEvents(?UpgradeRun $run = null): array
    {
        $run ??= $this->currentOrLastUpgradeRun();

        if (! $run instanceof UpgradeRun) {
            return [];
        }

        if ($this->recentUpgradeRunEvents !== null) {
            return $this->recentUpgradeRunEvents;
        }

        return $this->recentUpgradeRunEvents = $run->events()
            ->oldest('occurred_at')
            ->limit(12)
            ->get()
            ->all();
    }

    public function runStatusLabel(UpgradeRunStatus $status): string
    {
        return (string) __('capell-admin::generic.upgrade_run_status_' . $status->value);
    }

    public function eventLevelLabel(UpgradeRunEventLevel $level): string
    {
        return (string) __('capell-admin::generic.upgrade_event_level_' . $level->value);
    }

    public function upgradeStageLabel(UpgradeStage $stage): string
    {
        return (string) __('capell-admin::generic.upgrade_stage_' . $stage->value);
    }

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('checkForUpdates')
                ->label(__('capell-admin::button.check_now'))
                ->icon('heroicon-o-arrow-path')
                ->color('info')
                ->action(fn (): null => $this->checkForUpdates()),
            Action::make('dryRunUpgrade')
                ->label(__('capell-admin::button.preview_changes'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->authorize(fn (): bool => $this->canRunUpgrades())
                ->visible(fn (): bool => $this->canRunUpgrades())
                ->action(fn (): null => $this->runUpgrade(dryRun: true)),
            Action::make('runUpgrade')
                ->label(__('capell-admin::button.run_safe_update'))
                ->icon('heroicon-o-cloud-arrow-up')
                ->authorize(fn (): bool => $this->canRunUpgrades())
                ->visible(fn (): bool => $this->canRunUpgrades())
                ->requiresConfirmation()
                ->modalHeading(__('capell-admin::heading.upgrade_confirm'))
                ->modalDescription(__('capell-admin::generic.upgrade_confirm_description'))
                ->action(fn (): null => $this->runUpgrade(dryRun: false)),
        ];
    }

    private function runUpgrade(bool $dryRun): null
    {
        abort_unless($this->canRunUpgrades(), 403);

        $result = QueueCapellUpgradeAction::run($dryRun);
        $this->readinessReport = $result->readiness;
        $this->currentOrLastUpgradeRun = null;
        $this->recentUpgradeRunEvents = null;
        $queued = $result->queued();
        $this->lastExitCode = null;
        $this->lastOutput = $queued
            ? (string) __('capell-admin::message.upgrade_queued')
            : (string) __('capell-admin::message.upgrade_manual_required', [
                'command' => $this->manualUpgradeCommand($dryRun),
            ]);

        if (! $queued && $result->readiness->errors !== []) {
            $this->lastOutput .= PHP_EOL . PHP_EOL . Str::of(implode(PHP_EOL, $result->readiness->errors))->toString();
        }

        Notification::make($dryRun ? 'upgrade-preview-complete' : 'upgrade-complete')
            ->status($queued ? 'success' : 'warning')
            ->title($queued
                ? __('capell-admin::message.upgrade_queued')
                : __('capell-admin::message.upgrade_manual_required_short'))
            ->body($queued
                ? __('capell-admin::message.upgrade_queued_body')
                : __('capell-admin::message.upgrade_manual_required_body'))
            ->send();

        return null;
    }

    private function canRunUpgrades(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can(CapellPermission::RunUpgrades->name());
    }

    private function severityWeight(string $severity): int
    {
        return match ($severity) {
            'critical' => 400,
            'high' => 300,
            'medium' => 200,
            default => 100,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $notices
     * @return array<int, array<string, mixed>>
     */
    private function visibleNotices(array $notices): array
    {
        return collect($notices)
            ->reject(fn (array $notice): bool => $this->noticeIsDismissed($notice))
            ->values()
            ->all();
    }

    /**
     * @param  Notice  $notice
     */
    private function noticeIsDismissed(array $notice): bool
    {
        if ($this->isPersistentSecurityNotice($notice)) {
            return false;
        }

        $noticeId = $this->noticeId($notice);

        if ($noticeId === '') {
            return false;
        }

        return isset($this->dismissedNoticeIds()[$noticeId]);
    }

    /** @return array<string, true> */
    private function dismissedNoticeIds(): array
    {
        if ($this->dismissedNoticeIds !== null) {
            return $this->dismissedNoticeIds;
        }

        $userId = auth()->id();

        if (! is_int($userId) || ! Schema::hasTable(self::UPDATE_NOTICE_DISMISSALS_TABLE)) {
            return $this->dismissedNoticeIds = [];
        }

        try {
            $noticeIds = DB::table(self::UPDATE_NOTICE_DISMISSALS_TABLE)
                ->where('user_id', $userId)
                ->where(function (QueryBuilder $query): void {
                    $query
                        ->whereNull('dismissed_until')
                        ->orWhere('dismissed_until', '>', now());
                })
                ->pluck('notice_id')
                ->filter(fn (mixed $noticeId): bool => is_string($noticeId) && $noticeId !== '')
                ->mapWithKeys(fn (string $noticeId): array => [$noticeId => true])
                ->all();
        } catch (Throwable) {
            $noticeIds = [];
        }

        /** @var array<string, true> $noticeIds */
        return $this->dismissedNoticeIds = $noticeIds;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findNotice(string $noticeId): ?array
    {
        $snapshot = $this->latestAdvisorySnapshot();

        if (! $snapshot instanceof UpgradeAdvisorySnapshotData) {
            return null;
        }

        $notices = collect($snapshot->advisories)
            ->merge($snapshot->updates);

        $notice = $notices->first(fn (array $notice): bool => $this->noticeId($notice) === $noticeId);

        return is_array($notice) ? $notice : null;
    }

    /**
     * @param  Notice  $notice
     */
    private function isPersistentSecurityNotice(array $notice): bool
    {
        return $this->noticeData($notice)->isHighRiskSecurity();
    }

    /**
     * @param  Notice  $notice
     */
    private function noticeId(array $notice): string
    {
        return $this->noticeData($notice)->noticeId;
    }

    /**
     * @param  Notice  $notice
     */
    private function noticeData(array $notice): UpgradeNoticeData
    {
        return UpgradeNoticeData::fromPayload($notice);
    }
}
