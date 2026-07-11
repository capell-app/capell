<?php

declare(strict_types=1);

namespace Capell\Core\Data\Backup;

use Override;
use Spatie\LaravelData\Data;

final class BackupHealthReportData extends Data
{
    /**
     * @param  list<array{name: string, passed: bool, message: string}>  $checks
     */
    public function __construct(
        public string $status,
        public string $checkedAt,
        public int $snapshotCount,
        public ?string $newestSnapshotAt,
        public array $checks,
    ) {}

    public function passed(): bool
    {
        return $this->status === 'healthy';
    }

    /**
     * @return array{status: string, checked_at: string, snapshot_count: int, newest_snapshot_at: string|null, checks: list<array{name: string, passed: bool, message: string}>}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'checked_at' => $this->checkedAt,
            'snapshot_count' => $this->snapshotCount,
            'newest_snapshot_at' => $this->newestSnapshotAt,
            'checks' => $this->checks,
        ];
    }
}
