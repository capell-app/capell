<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Actions\Agent\CreateAgentBlueprintAction;
use Capell\Admin\Actions\Blueprints\UpdateBlueprintAction;
use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Models\Blueprint;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final readonly class AgentBlueprintWriteTool implements AgentAdminTool
{
    public function __construct(
        private AgentAdminAuthorization $authorization,
    ) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.blueprint.write',
            description: (string) __('capell-admin::agent.blueprint_write_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'operation' => ['type' => 'string', 'enum' => ['create', 'update']],
                    'id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'data' => ['type' => 'object', 'minProperties' => 1],
                ],
                'required' => ['operation', 'data'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'operation' => ['type' => 'string'],
                    'id' => ['type' => 'integer'],
                    'saved' => ['type' => 'boolean'],
                ],
                'required' => ['operation', 'id', 'saved'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Write,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('blueprint')),
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
            'operation' => ['required', 'string', 'in:create,update'],
            'id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'data' => ['required', 'array', 'min:1'],
        ])->validate();

        throw_unless($this->authorization->canUpdateGlobalSettings($invocation->user, $invocation->siteId), AuthorizationException::class);

        $operation = (string) $invocation->payload['operation'];
        $id = $invocation->payload['id'] ?? null;
        if ($operation === 'update' && (! is_int($id) && ! is_numeric($id))) {
            throw ValidationException::withMessages(['id' => __('capell-admin::agent.structure_id_required')]);
        }

        $this->data($invocation, $operation);

        if ($operation === 'update') {
            Blueprint::query()->whereKey((int) $id)->firstOrFail();
        }
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $operation = (string) $invocation->payload['operation'];

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'preview',
            tool: $invocation->tool,
            data: [
                'operation' => $operation,
                'id' => $invocation->payload['id'] ?? null,
                'before' => $this->current($invocation),
                'data' => $this->data($invocation, $operation),
            ],
        );
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $operation = (string) $invocation->payload['operation'];
        $data = $this->data($invocation, $operation);
        $blueprint = $operation === 'create'
            ? CreateAgentBlueprintAction::run($data)
            : UpdateBlueprintAction::run(Blueprint::query()->whereKey((int) $invocation->payload['id'])->firstOrFail(), $data);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: [
                'operation' => $operation,
                'id' => $blueprint->id,
                'saved' => true,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function data(AgentAdminToolInvocationData $invocation, string $operation): array
    {
        $data = $invocation->payload['data'] ?? null;
        if (! is_array($data)) {
            throw ValidationException::withMessages(['data' => __('capell-admin::agent.structure_data_invalid')]);
        }

        $rules = [
            'key' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9._-]{0,126}\z/'],
            'name' => ['required', 'string', 'max:191'],
            'group' => ['sometimes', 'nullable', 'string', 'max:191'],
            'order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', 'boolean'],
            'default' => ['sometimes', 'boolean'],
        ];

        if ($operation === 'create') {
            $rules['type'] = ['required', 'string'];
        }

        /** @var array<string, mixed> $validated */
        $validated = validator($data, $rules)->validate();
        $type = $validated['type'] ?? null;

        if (is_string($type) && ! resolve(BlueprintSubjectRegistry::class)->has($type)) {
            throw ValidationException::withMessages(['type' => __('capell-admin::agent.blueprint_type_invalid')]);
        }

        $query = Blueprint::query()->where('key', $validated['key']);
        if ($type !== null) {
            $query->where('type', $type);
        } elseif (is_numeric($invocation->payload['id'] ?? null)) {
            $existing = Blueprint::query()->whereKey((int) $invocation->payload['id'])->firstOrFail();
            $query->where('type', $existing->getRawOriginal('type'));
        }

        if (is_numeric($invocation->payload['id'] ?? null)) {
            $query->where('id', '!=', (int) $invocation->payload['id']);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages(['key' => __('capell-admin::agent.blueprint_key_taken')]);
        }

        if ($operation === 'update') {
            return $validated;
        }

        return [
            ...$validated,
            'order' => (int) ($validated['order'] ?? 0),
            'status' => (bool) ($validated['status'] ?? true),
            'default' => (bool) ($validated['default'] ?? false),
        ];
    }

    /** @return array<string, mixed>|null */
    private function current(AgentAdminToolInvocationData $invocation): ?array
    {
        if (! is_numeric($invocation->payload['id'] ?? null)) {
            return null;
        }

        $blueprint = Blueprint::query()->whereKey((int) $invocation->payload['id'])->firstOrFail();

        return [
            'id' => $blueprint->id,
            'type' => $blueprint->getRawOriginal('type'),
            'key' => $blueprint->key,
            'name' => $blueprint->name,
            'group' => $blueprint->group,
            'order' => $blueprint->order,
            'status' => $blueprint->status,
            'default' => $blueprint->default,
        ];
    }
}
