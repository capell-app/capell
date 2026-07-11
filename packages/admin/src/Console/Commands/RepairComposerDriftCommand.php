<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\Extensions\RepairComposerDriftAction;
use Capell\Core\Console\Commands\Concerns\DescribesCommandOptions;
use Illuminate\Console\Command;

final class RepairComposerDriftCommand extends Command
{
    use DescribesCommandOptions;

    protected $signature = 'capell:extensions:repair-composer-drift
        {package? : Specific Composer package name to repair}
        {--all : Repair all detected Composer-actionable drift when the config gate is enabled}
        {--force : Allow --all repair even when the config gate is disabled}';

    protected $description = 'Repair extension Composer drift without mutating dashboard read requests.';

    public function handle(): int
    {
        $package = $this->argument('package');
        $all = $this->option('all') === true;
        $this->writeCommandIntro('repair extension Composer drift', $this->enabledOptionDetails([
            'all' => 'all actionable drift',
            'force' => 'config gate bypassed',
        ]));

        if (! is_string($package) && ! $all) {
            $this->error((string) __('capell-admin::dashboard.extension_composer_drift_command_missing_target'));

            return self::FAILURE;
        }

        if (! is_string($package) && ! $this->canRepairAll()) {
            $this->warn((string) __('capell-admin::dashboard.extension_composer_drift_command_gate_disabled'));

            return self::SUCCESS;
        }

        $results = RepairComposerDriftAction::run(is_string($package) ? $package : null);

        if ($results === []) {
            $this->info((string) __('capell-admin::dashboard.extension_composer_drift_command_no_work'));

            return self::SUCCESS;
        }

        foreach ($results as $result) {
            $this->line(sprintf(
                '[%s] %s: %s',
                $result['status'],
                $result['package'],
                $result['message'],
            ));
        }

        /** @var list<array{package:string,status:string,message:string,reason:string|null}> $results */
        return collect($results)->contains(fn (array $result): bool => $result['status'] === 'failed')
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function canRepairAll(): bool
    {
        return config('capell-admin.extensions.composer_drift.auto_fix') === true
            || $this->option('force') === true;
    }
}
