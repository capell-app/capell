<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Spatie\LaravelData\Data;

/**
 * The warning count of a doctor report and the exact command that lists them.
 */
final class DoctorWarningSummaryData extends Data
{
    public const string LIST_COMMAND = 'php artisan capell:doctor --warnings';

    public function __construct(
        public readonly int $count,
        public readonly string $command = self::LIST_COMMAND,
    ) {}

    public function hasWarnings(): bool
    {
        return $this->count > 0;
    }

    public function message(): string
    {
        return trans_choice('capell-core::health.doctor.warning_summary', $this->count, [
            'count' => $this->count,
            'command' => $this->command,
        ]);
    }
}
