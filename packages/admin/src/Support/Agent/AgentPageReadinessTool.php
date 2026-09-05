<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Actions\Properties\EvaluatePropertyCompletenessAction;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;

final readonly class AgentPageReadinessTool implements AgentAdminTool
{
    public function __construct(private AgentAdminAuthorization $authorization) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.page.agent_readiness.read',
            description: (string) __('capell-admin::agent.page_readiness_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => ['page_id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['page_id'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer'],
                    'is_agent_complete' => ['type' => 'boolean'],
                    'missing_publish_required' => ['type' => 'array'],
                    'missing_contract_required' => ['type' => 'array'],
                ],
                'required' => ['page_id', 'is_agent_complete', 'missing_publish_required', 'missing_contract_required'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Read,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('page/readiness')),
            ownerPackage: 'capell-app/admin',
        );
    }

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool
    {
        return $this->authorization->canViewPages($invocation->user, $invocation->siteId);
    }

    public function authorize(AgentAdminToolInvocationData $invocation): void
    {
        validator($invocation->payload, ['page_id' => ['required', 'integer', 'min:1']])->validate();
        $this->authorization->page($invocation, 'view');
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        return $this->execute($invocation);
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $page = $this->authorization->page($invocation, 'view');
        $completeness = EvaluatePropertyCompletenessAction::run($page);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: [
                'page_id' => $page->id,
                'is_agent_complete' => $completeness->isAgentComplete(),
                'missing_publish_required' => $completeness->missingPublishRequired,
                'missing_contract_required' => $completeness->missingContractRequired,
            ],
        );
    }
}
