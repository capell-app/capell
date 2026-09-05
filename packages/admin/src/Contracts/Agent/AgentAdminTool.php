<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Agent;

use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;

/**
 * A permissioned tool available to an authenticated admin agent session.
 *
 * Tool definitions stay on Core's neutral data contract so the public bridge,
 * Creator server, and Admin registry cannot silently drift in shape. Admin
 * handlers remain here because they require the current user and site scope.
 */
interface AgentAdminTool
{
    public function definition(): AgentToolDefinitionData;

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool;

    public function authorize(AgentAdminToolInvocationData $invocation): void;

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData;

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData;
}
