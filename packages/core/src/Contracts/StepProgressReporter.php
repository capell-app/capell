<?php

declare(strict_types=1);

namespace Capell\Core\Contracts;

/**
 * A progress reporter that shows the install as a position within a known number of steps.
 */
interface StepProgressReporter extends ProgressReporter
{
    public function advance(int $position, int $total, string $label): void;

    /** Close the progress display after the last step has run. */
    public function finish(): void;

    /** Stop the progress display in its failed state, before the failure is explained. */
    public function abandon(): void;
}
