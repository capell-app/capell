<?php

declare(strict_types=1);

namespace Capell\Core\Data\Backup;

use Override;
use Spatie\LaravelData\Data;

final class BackupRestoreResultData extends Data
{
    public function __construct(
        public string $snapshotId,
        public string $database,
        public int $mediaFiles,
        public string $doctorStatus,
    ) {}

    /** @return array{snapshot_id: string, database: string, media_files: int, doctor_status: string} */
    #[Override]
    public function toArray(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'database' => $this->database,
            'media_files' => $this->mediaFiles,
            'doctor_status' => $this->doctorStatus,
        ];
    }
}
