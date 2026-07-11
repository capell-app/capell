<?php

declare(strict_types=1);

namespace Capell\Admin\Data\AdminPanelIntegration;

use Capell\Admin\Enums\AdminPanelChangeStatus;
use Spatie\LaravelData\Data;

final class AdminPanelSetupResultData extends Data
{
    /**
     * @param  array<int, AdminPanelChangeResultData>  $changes
     */
    public function __construct(
        public readonly ?string $panelPath,
        public readonly ?string $backupPath,
        public readonly array $changes,
        public readonly string $docsUrl,
    ) {}

    public function hasFailures(): bool
    {
        return collect($this->changes)
            ->contains(fn (AdminPanelChangeResultData $change): bool => $change->status === AdminPanelChangeStatus::Failed);
    }

    public function appliedCount(): int
    {
        return collect($this->changes)
            ->where('status', AdminPanelChangeStatus::Applied)
            ->count();
    }

    public function manualCount(): int
    {
        return collect($this->changes)
            ->where('status', AdminPanelChangeStatus::Manual)
            ->count();
    }

    public function hasAppliedChanges(): bool
    {
        return $this->appliedCount() > 0;
    }
}
