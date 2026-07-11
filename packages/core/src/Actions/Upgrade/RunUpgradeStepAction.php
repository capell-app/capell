<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Upgrade;

use Capell\Core\Contracts\UpgradeStepContract;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Data\UpgradeStepResult;
use Capell\Core\Enums\Upgrade\UpgradeStepStatus;
use Capell\Core\Models\UpgradeLogEntry;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

class RunUpgradeStepAction
{
    use AsAction;

    public function handle(
        UpgradeStepContract $step,
        UpgradeContext $context,
        bool $force = false,
    ): UpgradeStepResult {
        if ($context->dryRun) {
            return $this->result($step, UpgradeStepStatus::DryRun, 0, 'Would run');
        }

        if (! $force && $this->alreadySucceeded($step)) {
            return $this->result($step, UpgradeStepStatus::Skipped, 0, 'Already applied');
        }

        if (! $step->shouldRun($context)) {
            return $this->result($step, UpgradeStepStatus::Skipped, 0, 'shouldRun() returned false');
        }

        $missing = $this->missingDependencies($step);
        if ($missing !== []) {
            return $this->result(
                $step,
                UpgradeStepStatus::Skipped,
                0,
                sprintf('Missing dependency: %s', implode(', ', $missing)),
            );
        }

        $startedAt = microtime(true);

        try {
            DB::transaction(function () use ($step, $context, $startedAt): void {
                throw_unless($step->run($context), RuntimeException::class, 'run() returned false');

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                $this->persist($step, $context, UpgradeStepStatus::Success, $durationMs, null);
            });

            $status = UpgradeStepStatus::Success;
            $output = null;
        } catch (Throwable $throwable) {
            $status = UpgradeStepStatus::Failed;
            $output = $throwable->getMessage();

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->persist($step, $context, $status, $durationMs, $output);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return $this->result($step, $status, $durationMs, $output);
    }

    private function persist(
        UpgradeStepContract $step,
        UpgradeContext $context,
        UpgradeStepStatus $status,
        int $durationMs,
        ?string $output,
    ): void {
        $meta = [
            'duration_ms' => $durationMs,
            'output' => $output,
            'depends_on' => $step->dependsOn() !== [] ? $step->dependsOn() : null,
            'triggered_by' => $context->triggeredBy,
            'from_version' => $context->ledgerVersion($step->package()),
            'to_version' => $context->composerVersion($step->package()),
        ];

        UpgradeLogEntry::query()->create([
            'type' => 'step',
            'key' => $step->id(),
            'package' => $step->package(),
            'status' => $status->value,
            'ran_at' => now(),
            'meta' => RedactUpgradeRunContextAction::run(array_filter(
                $meta,
                static fn (mixed $value): bool => $value !== null,
            )),
        ]);
    }

    private function alreadySucceeded(UpgradeStepContract $step): bool
    {
        return UpgradeLogEntry::query()
            ->steps()
            ->where('key', $step->id())
            ->where('status', UpgradeStepStatus::Success->value)
            ->exists();
    }

    /** @return array<int, string> */
    private function missingDependencies(UpgradeStepContract $step): array
    {
        $required = $step->dependsOn();

        if ($required === []) {
            return [];
        }

        $satisfied = UpgradeLogEntry::query()
            ->steps()
            ->whereIn('key', $required)
            ->where('status', UpgradeStepStatus::Success->value)
            ->pluck('key')
            ->all();

        return array_values(array_diff($required, $satisfied));
    }

    private function result(UpgradeStepContract $step, UpgradeStepStatus $status, int $durationMs, ?string $output = null): UpgradeStepResult
    {
        return new UpgradeStepResult(
            stepId: $step->id(),
            label: $step->label(),
            status: $status->value,
            durationMs: $durationMs,
            output: $output,
        );
    }
}
