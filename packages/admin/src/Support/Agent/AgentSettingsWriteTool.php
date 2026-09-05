<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Actions\Agent\UpdateAgentSettingsAction;
use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AgentSettingsWriteTool implements AgentAdminTool
{
    public function __construct(
        private AgentAdminAuthorization $authorization,
        private UpdateAgentSettingsAction $settings,
    ) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.settings.write',
            description: (string) __('capell-admin::agent.settings_write_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'group' => ['type' => 'string', 'enum' => ['core', 'admin']],
                    'values' => ['type' => 'object', 'minProperties' => 1],
                ],
                'required' => ['group', 'values'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'group' => ['type' => 'string'],
                    'saved' => ['type' => 'boolean'],
                    'values' => ['type' => 'object'],
                ],
                'required' => ['group', 'saved', 'values'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Write,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('settings')),
            ownerPackage: 'capell-app/admin',
        );
    }

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool
    {
        return $this->authorization->canUpdateGlobalSettings($invocation->user, $invocation->siteId);
    }

    public function authorize(AgentAdminToolInvocationData $invocation): void
    {
        validator($invocation->payload, [
            'group' => ['required', 'string', 'in:core,admin'],
            'values' => ['required', 'array', 'min:1'],
        ])->validate();

        throw_unless($this->authorization->canUpdateGlobalSettings($invocation->user, $invocation->siteId), AuthorizationException::class);

        $this->settings->validate((string) $invocation->payload['group'], $invocation->payload['values']);
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $group = (string) $invocation->payload['group'];
        $values = $this->settings->validate($group, $invocation->payload['values']);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'preview',
            tool: $invocation->tool,
            data: [
                'group' => $group,
                'before' => $this->settings->current($group),
                'after' => $values,
            ],
        );
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $group = (string) $invocation->payload['group'];
        $values = $this->settings->handle($group, $invocation->payload['values']);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: [
                'group' => $group,
                'saved' => true,
                'values' => $values,
            ],
        );
    }
}
