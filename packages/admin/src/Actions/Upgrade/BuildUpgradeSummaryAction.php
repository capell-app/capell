<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Upgrade;

use Capell\Admin\Data\Upgrade\UpgradeNoticeData;
use Capell\Admin\Data\Upgrade\UpgradeSummaryData;
use Lorisleiva\Actions\Concerns\AsAction;

final class BuildUpgradeSummaryAction
{
    use AsAction;

    public function handle(): UpgradeSummaryData
    {
        $snapshot = ReadLatestUpgradeSnapshotAction::run();
        $advisories = $snapshot !== null ? $snapshot->advisories : [];
        $updates = $snapshot !== null ? $snapshot->updates : [];
        /** @var list<array<string, mixed>> $noticePayloads */
        $noticePayloads = array_merge($advisories, $updates);

        $notices = collect($noticePayloads)
            ->map(fn (array $notice): UpgradeNoticeData => UpgradeNoticeData::fromPayload($notice))
            ->filter(fn (UpgradeNoticeData $notice): bool => $notice->versionsBehind === null || $notice->versionsBehind > 0 || $notice->type === 'security')
            ->values()
            ->all();

        return UpgradeSummaryData::fromNotices(
            notices: $notices,
            dangerThreshold: config('capell-admin.upgrades.danger_threshold', 3),
        );
    }
}
