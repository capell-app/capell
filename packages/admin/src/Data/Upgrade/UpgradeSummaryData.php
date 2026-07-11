<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Upgrade;

use Spatie\LaravelData\Data;

final class UpgradeSummaryData extends Data
{
    /**
     * @param  array<int, UpgradeNoticeData>  $notices
     */
    public function __construct(
        public readonly int $securityCount,
        public readonly int $bugfixCount,
        public readonly int $featureCount,
        public readonly int $majorCount,
        public readonly int $updateCount,
        public readonly int $totalCount,
        public readonly int $maxVersionsBehind,
        public readonly ?string $navigationBadge,
        public readonly string $navigationBadgeColor,
        public readonly array $notices,
    ) {}

    /**
     * @param  array<int, UpgradeNoticeData>  $notices
     */
    public static function fromNotices(array $notices, int $dangerThreshold): self
    {
        $noticeCollection = collect($notices);

        $maxVersionsBehind = $noticeCollection
            ->map(fn (UpgradeNoticeData $notice): int => $notice->versionsBehind ?? 0)
            ->max() ?? 0;

        $securityCount = $noticeCollection->filter(fn (UpgradeNoticeData $notice): bool => $notice->type === 'security')->count();
        $bugfixCount = $noticeCollection->filter(fn (UpgradeNoticeData $notice): bool => $notice->type === 'bugfix')->count();
        $featureCount = $noticeCollection->filter(fn (UpgradeNoticeData $notice): bool => $notice->type === 'feature')->count();
        $majorCount = $noticeCollection->filter(fn (UpgradeNoticeData $notice): bool => $notice->type === 'major')->count();
        $hasHighRiskSecurity = $noticeCollection->contains(fn (UpgradeNoticeData $notice): bool => $notice->isHighRiskSecurity());

        return new self(
            securityCount: $securityCount,
            bugfixCount: $bugfixCount,
            featureCount: $featureCount,
            majorCount: $majorCount,
            updateCount: count($notices) - $securityCount,
            totalCount: count($notices),
            maxVersionsBehind: $maxVersionsBehind,
            navigationBadge: self::navigationBadge($maxVersionsBehind),
            navigationBadgeColor: self::navigationBadgeColor($maxVersionsBehind, $dangerThreshold, $hasHighRiskSecurity),
            notices: $notices,
        );
    }

    public function hasNotifications(): bool
    {
        return $this->totalCount > 0;
    }

    private static function navigationBadge(int $maxVersionsBehind): ?string
    {
        if ($maxVersionsBehind === 0) {
            return null;
        }

        $translationKey = 'capell-admin::generic.upgrade_nav_badge_behind';
        $badge = trans_choice($translationKey, $maxVersionsBehind, ['count' => $maxVersionsBehind]);

        return $badge === $translationKey ? sprintf('%d behind', $maxVersionsBehind) : $badge;
    }

    private static function navigationBadgeColor(int $maxVersionsBehind, int $dangerThreshold, bool $hasHighRiskSecurity): string
    {
        if ($hasHighRiskSecurity || $maxVersionsBehind >= $dangerThreshold) {
            return 'danger';
        }

        if ($maxVersionsBehind > 0) {
            return 'warning';
        }

        return 'success';
    }
}
