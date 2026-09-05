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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class AgentTermPropertiesReadTool implements AgentAdminTool
{
    public function __construct(
        private AgentAdminAuthorization $authorization,
        private AgentAdminPropertyValuePresenter $presenter,
    ) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.term.properties.read',
            description: (string) __('capell-admin::agent.term_properties_read_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => ['term_id' => ['type' => 'integer', 'minimum' => 1]],
                'required' => ['term_id'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => ['term_id' => ['type' => 'integer'], 'properties' => ['type' => 'array']],
                'required' => ['term_id', 'properties'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Read,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('term/properties')),
            ownerPackage: 'capell-app/admin',
        );
    }

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool
    {
        try {
            $this->authorization->site($invocation->user, $invocation->siteId);

            return true;
        } catch (ModelNotFoundException|AuthorizationException) {
            return false;
        }
    }

    public function authorize(AgentAdminToolInvocationData $invocation): void
    {
        validator($invocation->payload, ['term_id' => ['required', 'integer', 'min:1']])->validate();
        $this->authorization->term($invocation);
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        return $this->execute($invocation);
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $term = $this->authorization->term($invocation);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: [
                'term_id' => $term->id,
                'taxonomy' => $term->taxonomy?->key,
                'properties' => $this->presenter->term($term),
            ],
        );
    }
}
