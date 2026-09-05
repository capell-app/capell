<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Actions\Properties\AssignPageTermsAction;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Models\Page;

final readonly class AgentPageTermsWriteTool implements AgentAdminTool
{
    public function __construct(private AgentAdminAuthorization $authorization) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.page.terms.write',
            description: (string) __('capell-admin::agent.page_terms_write_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer', 'minimum' => 1],
                    'term_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer', 'minimum' => 1],
                        'uniqueItems' => true,
                    ],
                ],
                'required' => ['page_id', 'term_ids'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer'],
                    'term_ids' => ['type' => 'array'],
                    'assigned' => ['type' => 'boolean'],
                ],
                'required' => ['page_id', 'term_ids', 'assigned'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Write,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('page/terms')),
            ownerPackage: 'capell-app/admin',
        );
    }

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool
    {
        return $this->authorization->canUpdatePages($invocation->user, $invocation->siteId);
    }

    public function authorize(AgentAdminToolInvocationData $invocation): void
    {
        validator($invocation->payload, [
            'page_id' => ['required', 'integer', 'min:1'],
            'term_ids' => ['present', 'array', 'max:100'],
            'term_ids.*' => ['integer', 'min:1'],
        ])->validate();

        $this->authorization->page($invocation, 'update');
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $page = $this->authorization->page($invocation, 'update');
        $current = $this->termIds($page);
        $proposed = $this->proposedTermIds($invocation);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'preview',
            tool: $invocation->tool,
            data: [
                'page_id' => $page->id,
                'before_term_ids' => $current,
                'after_term_ids' => $proposed,
            ],
        );
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $page = $this->authorization->page($invocation, 'update');
        $termIds = $this->proposedTermIds($invocation);

        AssignPageTermsAction::run($page, $termIds);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: [
                'page_id' => $page->id,
                'term_ids' => $termIds,
                'assigned' => true,
            ],
        );
    }

    /** @return list<int> */
    private function termIds(Page $page): array
    {
        /** @var list<int> $termIds */
        $termIds = $page->terms()->pluck('terms.id')->map(static fn (mixed $id): int => (int) $id)->values()->all();

        return $termIds;
    }

    /** @return list<int> */
    private function proposedTermIds(AgentAdminToolInvocationData $invocation): array
    {
        $termIds = $invocation->payload['term_ids'] ?? [];

        if (! is_array($termIds)) {
            return [];
        }

        /** @var list<int> $normalised */
        $normalised = array_values(array_map(intval(...), $termIds));

        return $normalised;
    }
}
