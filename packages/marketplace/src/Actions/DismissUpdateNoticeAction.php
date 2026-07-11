<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Models\UpdateNoticeDismissal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class DismissUpdateNoticeAction
{
    use AsObject;

    /**
     * @param  array<string, mixed>  $notice
     */
    public function handle(int $userId, array $notice, ?DateTimeInterface $dismissedUntil = null): UpdateNoticeDismissal
    {
        throw_if($this->isPersistentSecurityNotice($notice), RuntimeException::class, 'High and critical security notices cannot be dismissed until the affected package is upgraded.');

        $noticeId = $this->noticeId($notice);

        throw_if($noticeId === '', RuntimeException::class, 'Update notice cannot be dismissed because it does not have a notice ID.');

        return UpdateNoticeDismissal::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'notice_id' => $noticeId,
            ],
            [
                'dismissed_until' => $dismissedUntil ?? CarbonImmutable::now()->addWeek(),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $notice
     */
    private function isPersistentSecurityNotice(array $notice): bool
    {
        return ($notice['type'] ?? null) === 'security'
            && in_array((string) ($notice['severity'] ?? ''), ['critical', 'high'], true);
    }

    /**
     * @param  array<string, mixed>  $notice
     */
    private function noticeId(array $notice): string
    {
        $noticeId = $notice['notice_id'] ?? $notice['id'] ?? null;

        return is_string($noticeId) ? $noticeId : '';
    }
}
