<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Security\EvaluatePendingSecurityRemediationsAction;
use Capell\Core\Actions\Security\RefreshSecurityNodeAction;
use Capell\Core\Enums\Security\SecurityRemediationOutcome;
use Capell\Core\Models\SecurityRemediationRun;
use Capell\Core\Support\Security\SecurityNodeRegistry;
use Illuminate\Console\Command;
use Throwable;

final class SecurityRefreshNodeCommand extends Command
{
    protected $signature = 'capell:security:refresh-node {--retire=}';

    public function __construct()
    {
        parent::__construct();
        $this->setDescription(__('capell-core::security.command.refresh_description'));
    }

    public function handle(SecurityNodeRegistry $nodes): int
    {
        if (! $nodes->enabled()) {
            $this->line(__('capell-core::security.command.refresh_single_node'));

            return self::SUCCESS;
        }

        $retire = $this->option('retire');
        if (is_string($retire)) {
            try {
                $nodes->retire($retire);
                EvaluatePendingSecurityRemediationsAction::run();
                $this->line(__('capell-core::security.command.retired', ['node' => $retire]));

                return self::SUCCESS;
            } catch (Throwable) {
                $this->error(__('capell-core::security.command.registry_unknown'));

                return 2;
            }
        }

        $pendingIds = SecurityRemediationRun::query()->where('outcome', SecurityRemediationOutcome::AppliedPendingNodes->value)->pluck('id');
        try {
            $nodes->register();
            $nodes->activeNodes();
            $completed = EvaluatePendingSecurityRemediationsAction::run();
            $result = RefreshSecurityNodeAction::run();
            $completed += EvaluatePendingSecurityRemediationsAction::run();
        } catch (Throwable) {
            $this->error(__('capell-core::security.command.registry_unknown'));

            return 2;
        }

        $this->line(__('capell-core::security.command.refresh_node', ['node' => $nodes->nodeId(), 'acknowledged' => $result->acknowledged, 'completed' => $completed]));

        $evaluationFailures = SecurityRemediationRun::query()->whereIn('id', $pendingIds)->where('outcome', SecurityRemediationOutcome::Failed->value)->pluck('message')->all();
        foreach (array_unique([...$result->failures, ...$evaluationFailures]) as $failure) {
            $this->error($failure);
        }

        if ($result->acknowledged === 0 && $result->failures === []) {
            $this->error(__('capell-core::security.command.refresh_no_acknowledgement'));
        }

        return $result->acknowledged === 0 || $result->failures !== [] || $evaluationFailures !== [] ? 2 : self::SUCCESS;
    }
}
