<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Diagnostics\SchedulerHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;

/**
 * Names the hosting platform and release-root mode, and warns about the
 * hosting mistakes that leave a site rendering while background work stops.
 *
 * Every hazard here lets the site keep serving pages, so this is a warning:
 * it must never block a deploy, only tell the operator what will go wrong.
 */
final class HostingPlatformCheck extends AbstractDoctorCheck
{
    public const string RECIPE_URL = 'https://docs.capell.app/core/operations/deploy-on-laravel-cloud/';

    private const string PLATFORM_LARAVEL_CLOUD = 'laravel-cloud';

    private const string PLATFORM_SELF_MANAGED = 'self-managed';

    public function __construct(
        private readonly Application $application,
        private readonly SchedulerHeartbeat $heartbeat,
    ) {}

    protected function id(): string
    {
        return 'core.hosting.platform';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $signal = $this->laravelCloudSignal();
        $platform = $signal !== null ? self::PLATFORM_LARAVEL_CLOUD : self::PLATFORM_SELF_MANAGED;
        $releaseRootMode = $this->configString('capell.release_root_mode', 'mutable');
        $queueConnection = $this->configString('queue.default', 'sync');
        $queueDriver = $this->configString(sprintf('queue.connections.%s.driver', $queueConnection), $queueConnection);
        $production = $this->application->environment('production');
        $beatAt = $this->heartbeat->lastBeatAt();

        /** @var array<string, array{message: string, remediation: string}> $hazards */
        $hazards = [];

        if ($platform === self::PLATFORM_LARAVEL_CLOUD && $releaseRootMode === 'mutable') {
            $hazards['mutable_release_root'] = [
                'message' => $this->text(__('capell-core::health.hosting_platform.mutable_release_root')),
                'remediation' => $this->text(__('capell-core::health.hosting_platform.mutable_release_root_remediation')),
            ];
        }

        if ($production && $queueDriver === 'sync') {
            $hazards['sync_queue'] = [
                'message' => $this->text(__('capell-core::health.hosting_platform.sync_queue', ['connection' => $queueConnection])),
                'remediation' => $this->text(__('capell-core::health.hosting_platform.sync_queue_remediation')),
            ];
        }

        if ($production && ! $installSummary) {
            if (! $beatAt instanceof CarbonImmutable) {
                $hazards['scheduler_heartbeat_missing'] = [
                    'message' => $this->text(__('capell-core::health.hosting_platform.scheduler_heartbeat_missing')),
                    'remediation' => $this->text(__('capell-core::health.hosting_platform.scheduler_remediation')),
                ];
            } elseif ($this->heartbeat->isStale($beatAt)) {
                $hazards['scheduler_heartbeat_stale'] = [
                    'message' => $this->text(__('capell-core::health.hosting_platform.scheduler_heartbeat_stale', ['time' => $beatAt->toIso8601String()])),
                    'remediation' => $this->text(__('capell-core::health.hosting_platform.scheduler_remediation')),
                ];
            }
        }

        $platformLabel = $platform === self::PLATFORM_LARAVEL_CLOUD
            ? $this->text(__('capell-core::health.hosting_platform.platforms.laravel-cloud'))
            : $this->text(__('capell-core::health.hosting_platform.platforms.self-managed'));
        $summary = $this->text(__('capell-core::health.hosting_platform.summary', [
            'platform' => $platformLabel,
            'mode' => $releaseRootMode,
            'connection' => $queueConnection,
        ]));

        $evidence = [
            'platform' => $platform,
            'platform_signal' => $signal,
            'release_root_mode' => $releaseRootMode,
            'queue_connection' => $queueConnection,
            'queue_driver' => $queueDriver,
            'production' => $production,
            'scheduler_heartbeat_at' => $beatAt?->toIso8601String(),
            'hazards' => array_keys($hazards),
        ];

        if ($hazards === []) {
            return new DoctorCheckResultData(
                label: $this->text(__('capell-core::health.hosting_platform.label')),
                passed: true,
                message: $summary,
                severity: $this->severity(),
                evidence: $evidence,
            );
        }

        $remediation = array_values(array_unique(array_column($hazards, 'remediation')));
        $remediation[] = $this->text(__('capell-core::health.hosting_platform.recipe', ['url' => self::RECIPE_URL]));

        return new DoctorCheckResultData(
            label: $this->text(__('capell-core::health.hosting_platform.label')),
            passed: false,
            message: implode(' ', [$summary, ...array_column($hazards, 'message')]),
            remediation: implode(' ', $remediation),
            severity: $this->severity(),
            evidence: $evidence,
        );
    }

    /**
     * Laravel Cloud sets LARAVEL_CLOUD=1, which the framework's own
     * laravel_cloud() helper reads. Capell's connect flow also sets
     * CAPELL_INSTALL_MODE=cloud, which identifies the platform before the
     * framework marker is visible, for example in a deploy command.
     */
    private function laravelCloudSignal(): ?string
    {
        if (laravel_cloud()) {
            return 'LARAVEL_CLOUD';
        }

        if ($this->configString('capell.cloud.install_mode', '') === 'cloud') {
            return 'CAPELL_INSTALL_MODE';
        }

        return null;
    }

    private function text(mixed $line): string
    {
        return is_string($line) ? $line : '';
    }

    private function configString(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }
}
