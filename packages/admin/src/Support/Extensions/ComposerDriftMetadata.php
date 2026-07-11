<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Extensions;

use Capell\Core\Models\CapellExtension;

final class ComposerDriftMetadata
{
    public const LAST_REPAIR_ATTEMPTED_AT = 'composer_drift_last_repair_attempted_at';

    public const LAST_REPAIR_STATUS = 'composer_drift_last_repair_status';

    public const LAST_REPAIR_MESSAGE = 'composer_drift_last_repair_message';

    public const LAST_DETECTED_REASON = 'composer_drift_last_detected_reason';

    public static function recordDetection(CapellExtension $extension, string $reason): void
    {
        $extension->forceFill([
            'metadata' => [
                ...($extension->metadata ?? []),
                self::LAST_DETECTED_REASON => $reason,
            ],
        ])->save();
    }

    public static function recordRepair(CapellExtension $extension, string $status, string $message): void
    {
        $extension->forceFill([
            'metadata' => [
                ...($extension->metadata ?? []),
                self::LAST_REPAIR_ATTEMPTED_AT => now()->toISOString(),
                self::LAST_REPAIR_STATUS => $status,
                self::LAST_REPAIR_MESSAGE => $message,
            ],
        ])->save();
    }

    /**
     * @return array{status:string,message:string}|null
     */
    public static function lastRepairAttempt(CapellExtension $extension): ?array
    {
        $status = $extension->metadata[self::LAST_REPAIR_STATUS] ?? null;
        $message = $extension->metadata[self::LAST_REPAIR_MESSAGE] ?? null;

        if (! is_string($status) || $status === '' || ! is_string($message) || $message === '') {
            return null;
        }

        return [
            'status' => $status,
            'message' => $message,
        ];
    }
}
