<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Security\EvaluatePendingSecurityRemediationsAction;
use Capell\Core\Actions\Security\RefreshSecurityNodeAction;
use Capell\Core\Support\Security\SecurityNodeRegistry;
use Illuminate\Console\Command;

final class SecurityRefreshNodeCommand extends Command
{
    protected $signature = 'capell:security:refresh-node';

    protected $description = 'Apply approved security changes on this node and report it to the rest of the fleet.';

    public function handle(SecurityNodeRegistry $nodes): int
    {
        if (! $nodes->enabled()) {
            $this->line(__('capell-core::security.command.refresh_single_node'));

            return self::SUCCESS;
        }

        $acknowledged = RefreshSecurityNodeAction::run();
        $completed = EvaluatePendingSecurityRemediationsAction::run();
        $this->line(__('capell-core::security.command.refresh_node', ['node' => $nodes->nodeId(), 'acknowledged' => $acknowledged, 'completed' => $completed]));

        return self::SUCCESS;
    }
}
