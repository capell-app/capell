<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Pages;

use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Capell\Admin\Actions\CheckForUpdatesAction;
use Capell\Admin\Actions\Upgrade\BuildUpgradeSummaryAction;
use Capell\Admin\Actions\Upgrade\QueueCapellUpgradeAction;
use Capell\Admin\Enums\CapellPermission;
use Capell\Core\Actions\Upgrade\BuildUpgradeReadinessReportAction;
use Capell\Core\Data\Upgrade\UpgradeReadinessReportData;
use Capell\Core\Enums\Upgrade\UpgradeRunEventLevel;
use Capell\Core\Enums\Upgrade\UpgradeRunStatus;
use Capell\Core\Enums\Upgrade\UpgradeStage;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\UpgradeRun;
use Capell\Core\Models\UpgradeRunEvent;
use Capell\Core\Support\Json\JsonCodec;
use Carbon\CarbonImmutable;
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
use stdClass;
use Throwable;

/**
 * @phpstan-type Notice array<string, mixed>
 */
class UpgradePage extends Page
{
    use HasPageShield;

    private const string UPDATE_ADVISORY_SNAPSHOTS_TABLE = 'marketplace_update_advisory_snapshots';

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

    /**
     * @return (stdClass&object{advisories: array<int, array<string, mixed>>, updates: array<int, array<string, mixed>>, checked_at: CarbonImmutable|null, capell_version: string|null})|null
     */
    public function latestAdvisorySnapshot(): ?object
    {
        if (! Schema::hasTable(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)) {
            return null;
        }

        try {
            $snapshot = DB::table(self::UPDATE_ADVISORY_SNAPSHOTS_TABLE)
                ->latest('checked_at')
                ->first();
        } catch (Throwable) {
            return null;
        }

        if ($snapshot === null) {
            return null;
        }

        /** @var stdClass&object{advisories: array<int, array<string, mixed>>, updates: array<int, array<string, mixed>>, checked_at: CarbonImmutable|null, capell_version: string|null} $advisorySnapshot */
        $advisorySnapshot = (object) [
            'advisories' => $this->decodeNoticeList($snapshot->advisories ?? null),
            'updates' => $this->decodeNoticeList($snapshot->updates ?? null),
            'checked_at' => filled($snapshot->checked_at ?? null)
                ? CarbonImmutable::parse($snapshot->checked_at)
                : null,
            'capell_version' => is_string($snapshot->capell_version ?? null)
                ? $snapshot->capell_version
                : null,
        ];

        return $advisorySnapshot;
    }

    public function installedCapellVersion(): string
    {
        $snapshot = $this->latestAdvisorySnapshot();

        return CapellCore::getInstalledPrettyVersion('capell-app/capell')
            ?? ($snapshot !== null ? $snapshot->capell_version : null)
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
            ->map(fn (array $notice): ?int => $this->noticeVersionsBehind($notice))
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
        $type = $notice['update_type'] ?? $notice['release_type'] ?? $notice['type'] ?? null;

        if (is_string($type) && in_array($type, ['security', 'bugfix', 'bug', 'feature', 'major'], true)) {
            return $type === 'bug' ? 'bugfix' : $type;
        }

        $installedVersion = $this->noticeInstalledVersion($notice);
        $recommendedVersion = $this->noticeRecommendedVersion($notice);

        if ($this->majorVersion($installedVersion) !== null
            && $this->majorVersion($recommendedVersion) !== null
            && $this->majorVersion($installedVersion) !== $this->majorVersion($recommendedVersion)) {
            return 'major';
        }

        return 'feature';
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeImpactLabel(array $notice): string
    {
        $type = $this->noticeUpdateType($notice);
        $severity = (string) ($notice['severity'] ?? 'low');

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

        if ($snapshot === null) {
            return [];
        }

        $notices = collect($snapshot->advisories)
            ->where('type', 'security')
            ->sortByDesc(fn (array $notice): int => $this->severityWeight((string) ($notice['severity'] ?? 'low')))
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

        if ($snapshot === null) {
            return [];
        }

        $notices = collect($snapshot->advisories)
            ->where('type', 'bug')
            ->sortByDesc(fn (array $notice): int => $this->severityWeight((string) ($notice['severity'] ?? 'low')))
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

        if ($snapshot === null) {
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
        $names = collect([
            $notice['composer_name'] ?? null,
            $notice['package'] ?? null,
        ]);

        $composerNames = $names
            ->merge(collect(is_array($notice['affected_packages'] ?? null) ? $notice['affected_packages'] : [])
                ->filter(fn (mixed $package): bool => is_array($package))
                ->map(fn (array $package): mixed => $package['composer_name'] ?? null))
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->unique()
            ->values()
            ->all();

        return array_values($composerNames);
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
        $installedVersion = $notice['installed_version'] ?? $notice['current_version'] ?? null;

        if (is_string($installedVersion) && $installedVersion !== '') {
            return $installedVersion;
        }

        $affectedVersion = $this->noticeAffectedPackageVersion($notice, 'installed_version');

        return $affectedVersion ?? (string) __('capell-admin::generic.unknown');
    }

    /**
     * @param  Notice  $notice
     */
    public function noticeRecommendedVersion(array $notice): string
    {
        $recommendedVersion = $notice['recommended_version'] ?? $notice['latest_version'] ?? null;

        if (is_string($recommendedVersion) && $recommendedVersion !== '') {
            return $recommendedVersion;
        }

        $affectedVersion = $this->noticeAffectedPackageVersion($notice, 'fixed_version');

        if ($affectedVersion !== null) {
            return $affectedVersion;
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

    /**
     * @param  Notice  $notice
     */
    private function noticeAffectedPackageVersion(array $notice, string $versionKey): ?string
    {
        if (! is_array($notice['affected_packages'] ?? null)) {
            return null;
        }

        foreach ($notice['affected_packages'] as $package) {
            $version = is_array($package) ? ($package[$versionKey] ?? null) : null;

            if (is_string($version) && $version !== '') {
                return $version;
            }
        }

        return null;
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

        if ($snapshot === null) {
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
        return ($notice['type'] ?? null) === 'security'
            && in_array((string) ($notice['severity'] ?? ''), ['critical', 'high'], true);
    }

    /**
     * @param  Notice  $notice
     */
    private function noticeId(array $notice): string
    {
        $noticeId = $notice['notice_id'] ?? $notice['id'] ?? null;

        return is_string($noticeId) ? $noticeId : '';
    }

    /**
     * @param  Notice  $notice
     */
    private function noticeVersionsBehind(array $notice): ?int
    {
        $installedVersion = $this->comparableVersion($this->noticeInstalledVersion($notice));
        $recommendedVersion = $this->comparableVersion($this->noticeRecommendedVersion($notice));

        if ($installedVersion === null || $recommendedVersion === null) {
            return null;
        }

        if (version_compare($recommendedVersion, $installedVersion) <= 0) {
            return 0;
        }

        $installedParts = array_map(intval(...), explode('.', $installedVersion));
        $recommendedParts = array_map(intval(...), explode('.', $recommendedVersion));

        if ($installedParts[0] !== $recommendedParts[0]) {
            return max(1, ($recommendedParts[0] - $installedParts[0]) * 10);
        }

        if (($installedParts[1] ?? 0) !== ($recommendedParts[1] ?? 0)) {
            return max(1, ($recommendedParts[1] ?? 0) - ($installedParts[1] ?? 0));
        }

        return max(1, ($recommendedParts[2] ?? 0) - ($installedParts[2] ?? 0));
    }

    private function majorVersion(string $version): ?int
    {
        $comparableVersion = $this->comparableVersion($version);

        if ($comparableVersion === null) {
            return null;
        }

        return (int) explode('.', $comparableVersion)[0];
    }

    private function comparableVersion(string $version): ?string
    {
        if (preg_match('/v?(\d+(?:\.\d+){0,2})/i', $version, $matches) !== 1) {
            return null;
        }

        $parts = explode('.', $matches[1]);

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeNoticeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, is_array(...)));
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = JsonCodec::decodeArray($value);

        return array_values(array_filter($decoded, is_array(...)));
    }
}
