<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Backup;

use Capell\Core\Data\Backup\BackupArtifactData;
use Capell\Core\Data\Backup\BackupHealthReportData;
use Capell\Core\Data\Backup\BackupManifestData;
use Capell\Core\Support\Backup\BackupArtifactStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;
use UnexpectedValueException;

/** @method static BackupHealthReportData run() */
final class InspectBackupHealthAction
{
    use AsObject;

    public function __construct(
        private readonly Repository $config,
        private readonly BackupArtifactStore $store,
    ) {}

    public function handle(): BackupHealthReportData
    {
        $now = CarbonImmutable::now('UTC');

        try {
            $this->store->assertAvailable();
            $snapshotIds = $this->store->snapshotIds();
        } catch (Throwable $throwable) {
            return $this->report($now, 0, null, [[
                'name' => 'configuration',
                'passed' => false,
                'message' => $throwable->getMessage(),
            ]]);
        }

        $checks = [[
            'name' => 'configuration',
            'passed' => true,
            'message' => 'Backup storage is configured and accessible.',
        ]];
        $manifests = [];
        $integrityIssues = [];

        foreach ($snapshotIds as $snapshotId) {
            try {
                $manifest = BackupManifestData::fromManifestArray($this->store->manifest($snapshotId));

                if ($manifest->snapshotId !== $snapshotId) {
                    throw new UnexpectedValueException('manifest identity does not match its snapshot');
                }

                $createdAt = CarbonImmutable::parse($manifest->createdAt);

                if ($createdAt->isAfter($now->addMinutes(5))) {
                    throw new UnexpectedValueException('manifest timestamp is in the future');
                }

                $manifests[] = $manifest;
                $issue = $this->manifestArtifactIssue($manifest);

                if ($issue !== null) {
                    $integrityIssues[] = sprintf('Snapshot [%s] %s.', $snapshotId, $issue);
                }
            } catch (Throwable) {
                $integrityIssues[] = sprintf('Snapshot [%s] has an invalid manifest.', $snapshotId);
            }
        }

        $snapshotCount = count($manifests);
        $checks[] = [
            'name' => 'snapshots',
            'passed' => $snapshotCount > 0,
            'message' => $snapshotCount > 0 ? sprintf('%d completed snapshot(s) found.', $snapshotCount) : 'No completed backup snapshots were found.',
        ];
        $newest = $this->newestCreatedAt($manifests);
        $maxAgeHours = max(1, (int) $this->config->get('backup.max_age_hours', 26));
        $fresh = $newest !== null && ! $newest->isBefore($now->subHours($maxAgeHours));
        $checks[] = [
            'name' => 'freshness',
            'passed' => $fresh,
            'message' => $fresh ? 'The newest backup snapshot is fresh.' : sprintf('No backup snapshot is newer than %d hour(s).', $maxAgeHours),
        ];
        $minimumRetained = max(1, (int) $this->config->get('backup.minimum_retained', 7));
        $checks[] = [
            'name' => 'retention',
            'passed' => $snapshotCount >= $minimumRetained,
            'message' => $snapshotCount >= $minimumRetained
                ? sprintf('Retention contains at least %d completed snapshot(s).', $minimumRetained)
                : sprintf('Retention requires at least %d completed snapshot(s).', $minimumRetained),
        ];
        $checks[] = [
            'name' => 'integrity',
            'passed' => $integrityIssues === [],
            'message' => $integrityIssues === [] ? 'All snapshot manifests and artifacts passed integrity checks.' : implode(' ', $integrityIssues),
        ];

        return $this->report($now, $snapshotCount, $newest?->toAtomString(), $checks);
    }

    private function manifestArtifactIssue(BackupManifestData $manifest): ?string
    {
        foreach ([$manifest->database, ...$manifest->media] as $artifact) {
            $issue = $this->artifactIssue($manifest->snapshotId, $artifact);

            if ($issue !== null) {
                return $issue;
            }
        }

        return null;
    }

    private function artifactIssue(string $snapshotId, BackupArtifactData $artifact): ?string
    {
        return $this->store->artifactIssue($snapshotId, $artifact->path, $artifact->bytes, $artifact->sha256);
    }

    /**
     * @param  list<BackupManifestData>  $manifests
     */
    private function newestCreatedAt(array $manifests): ?CarbonImmutable
    {
        $newest = null;

        foreach ($manifests as $manifest) {
            $createdAt = CarbonImmutable::parse($manifest->createdAt);

            if ($newest === null || $createdAt->isAfter($newest)) {
                $newest = $createdAt;
            }
        }

        return $newest;
    }

    /**
     * @param  list<array{name: string, passed: bool, message: string}>  $checks
     */
    private function report(CarbonImmutable $now, int $snapshotCount, ?string $newestSnapshotAt, array $checks): BackupHealthReportData
    {
        $passed = array_all($checks, static fn (array $check): bool => $check['passed']);

        return new BackupHealthReportData(
            status: $passed ? 'healthy' : 'degraded',
            checkedAt: $now->toAtomString(),
            snapshotCount: $snapshotCount,
            newestSnapshotAt: $newestSnapshotAt,
            checks: $checks,
        );
    }
}
