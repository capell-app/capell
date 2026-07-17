<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Backup\InspectBackupHealthAction;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;

final class BackupHealthCommand extends Command
{
    protected $signature = 'capell:backup:health {--json : Output a machine-readable JSON health report}';

    protected $description = 'Verify Capell backup freshness, retention and artifact integrity.';

    public function handle(InspectBackupHealthAction $inspectBackupHealth): int
    {
        $report = InspectBackupHealthAction::run();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return $report->passed() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
        }

        foreach ($report->checks as $check) {
            $this->components->twoColumnDetail($check['name'], $check['message']);
        }

        return $report->passed() ? CommandAlias::SUCCESS : CommandAlias::FAILURE;
    }
}
