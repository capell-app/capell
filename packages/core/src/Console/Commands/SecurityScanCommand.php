<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Security\ApplyApprovedSecurityRemediationsAction;
use Capell\Core\Actions\Security\ListSecurityWeaknessFindingsAction;
use Capell\Core\Actions\Security\RunSecurityWeaknessScanAction;
use Capell\Core\Enums\Security\SecurityFindingStatus;
use Capell\Core\Enums\Security\SecurityWeaknessSeverity;
use Capell\Core\Models\SecurityRemediationApproval;
use Capell\Core\Models\SecurityRemediationRun;
use Illuminate\Console\Command;

final class SecurityScanCommand extends Command
{
    protected $signature = 'capell:security:scan {--fix-approved} {--dry-run} {--no-analyser} {--json} {--only=} {--actor=console}';

    protected $description = 'Scan the installation for security weaknesses.';

    public function handle(): int
    {
        $scan = RunSecurityWeaknessScanAction::run(! $this->option('no-analyser'));
        $batch = $this->option('fix-approved') ? ApplyApprovedSecurityRemediationsAction::run(
            $this->option('actor') === 'scheduler' ? SecurityRemediationRun::ACTOR_SCHEDULER : 'system:console',
            (bool) $this->option('dry-run'),
            $this->option('only'),
            $scan->scannedAt,
            array_values(array_map(static fn (string $failure): string => explode(': ', substr($failure, 7), 2)[0], array_filter($scan->failures, static fn (string $failure): bool => str_starts_with($failure, 'check: ')))),
        ) : null;
        $findings = ListSecurityWeaknessFindingsAction::run();
        $approvals = SecurityRemediationApproval::query()->active()->pluck('weakness_id')->all();
        $rows = $findings->map(fn ($finding): array => [
            'severity' => $finding->severity->value, 'weakness' => $finding->weakness_id,
            'title' => $finding->title, 'remediable' => $finding->remediable,
            'approved' => in_array($finding->weakness_id, $approvals, true),
        ])->all();
        if ($this->option('json')) {
            $this->line(json_encode(['scan' => $scan->toArray(), 'findings' => $rows, 'remediations' => $batch?->toArray()], JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Severity', 'Weakness', 'Title', 'Remediable', 'Approved'], array_map(
                fn (array $row): array => [$row['severity'], $row['weakness'], $row['title'], $row['remediable'] ? 'yes' : 'no', $row['approved'] ? 'yes' : 'no'],
                $rows,
            ));
            if ($batch !== null) {
                $this->line(__('capell-core::security.command.runs', ['applied' => $batch->applied, 'failed' => $batch->failed]));
                foreach (SecurityRemediationRun::query()->whereIn('id', $batch->runIds)->get() as $run) {
                    $this->line($run->weakness_id . ': ' . $run->outcome->value . ' — ' . $run->message);
                }

                foreach ($batch->skipped as $fingerprint => $reason) {
                    $this->line($fingerprint . ': ' . $reason);
                }
            }

            foreach ($scan->failures as $failure) {
                $this->warn($failure);
            }
        }

        if ($scan->failures !== [] || ($batch !== null && $batch->failed > 0)) {
            return 2;
        }

        return $findings->contains(fn ($finding): bool => $finding->status === SecurityFindingStatus::Open && $finding->severity === SecurityWeaknessSeverity::Critical) ? self::FAILURE : self::SUCCESS;
    }
}
