<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;

final readonly class AgentPagePropertiesReadTool implements AgentAdminTool
{
    public function __construct(
        private AgentAdminAuthorization $authorization,
        private AgentAdminPropertyValuePresenter $presenter,
    ) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.page.properties.read',
            description: (string) __('capell-admin::agent.page_properties_read_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => ['page_id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['page_id'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => ['page_id' => ['type' => 'integer'], 'properties' => ['type' => 'array']],
                'required' => ['page_id', 'properties'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Read,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('page/properties')),
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

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: ['page_id' => $page->id, 'properties' => $this->presenter->page($page)],
        );
    }
}
