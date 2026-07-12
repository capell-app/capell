<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Backup;

use Capell\Core\Data\Backup\BackupManifestData;
use Capell\Core\Support\Backup\BackupArtifactStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/** @method static list<string> run(bool $force = false) */
final class PruneBackupsAction
{
    use AsObject;

    public function __construct(
        private readonly Repository $config,
        private readonly BackupArtifactStore $store,
    ) {}

    /** @return list<string> */
    public function handle(bool $force = false): array
    {
        $this->store->assertAvailable();
        $retain = (int) $this->config->get('backup.retain', 30);

        throw_if($retain < 1, RuntimeException::class, 'Backup retention must keep at least one completed snapshot.');

        $completed = [];

        foreach ($this->store->snapshotIds() as $snapshotId) {
            try {
                $manifest = BackupManifestData::fromManifestArray($this->store->manifest($snapshotId));

                if ($manifest->snapshotId === $snapshotId
                    && ! CarbonImmutable::parse($manifest->createdAt)->isAfter(CarbonImmutable::now('UTC')->addMinutes(5))) {
                    $completed[] = ['id' => $snapshotId, 'created_at' => $manifest->createdAt];
                }
            } catch (Throwable) {
                // Never prune incomplete or malformed snapshots automatically.
            }
        }

        usort($completed, static fn (array $left, array $right): int => strcmp($right['created_at'], $left['created_at']));
        $candidates = array_values(array_map(
            static fn (array $snapshot): string => $snapshot['id'],
            array_slice($completed, $retain),
        ));

        if ($force) {
            foreach ($candidates as $snapshotId) {
                $this->store->deleteSnapshot($snapshotId);
            }
        }

        return $candidates;
    }
}
