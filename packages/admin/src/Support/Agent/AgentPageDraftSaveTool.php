<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Actions\Pages\SavePageEditorScratchDraftAction;
use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Admin\Data\Pages\PageEditorScratchDraftInputData;
use Capell\Admin\Enums\PageEditorScratchDraftStatus;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Models\EditorScratchDraft;
use Capell\Core\Models\Page;
use Illuminate\Validation\ValidationException;

final readonly class AgentPageDraftSaveTool implements AgentAdminTool
{
    public function __construct(private AgentAdminAuthorization $authorization) {}

    public function definition(): AgentToolDefinitionData
    {
        return new AgentToolDefinitionData(
            name: 'admin.page.draft.save',
            description: (string) __('capell-admin::agent.page_draft_save_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer', 'minimum' => 1],
                    'locale' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 12],
                    'fields' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string', 'maxLength' => 255],
                            'meta' => ['type' => ['object', 'null']],
                            'admin' => ['type' => ['object', 'null']],
                            'content_structure_override' => ['type' => ['string', 'null']],
                        ],
                        'additionalProperties' => false,
                    ],
                    'translations' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer', 'minimum' => 1],
                                'title' => ['type' => ['string', 'null']],
                                'content' => ['type' => ['string', 'object', 'array', 'number', 'boolean', 'null']],
                                'meta' => ['type' => ['object', 'null']],
                            ],
                            'required' => ['id'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['page_id'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer'],
                    'saved' => ['type' => 'boolean'],
                    'locale' => ['type' => 'string'],
                    'scratch_saved' => ['type' => 'boolean'],
                ],
                'required' => ['page_id', 'saved', 'locale', 'scratch_saved'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Write,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('page/draft')),
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
            'locale' => ['sometimes', 'string', 'max:12'],
            'fields' => ['sometimes', 'array'],
            'fields.name' => ['sometimes', 'string', 'max:255'],
            'fields.meta' => ['sometimes', 'nullable', 'array'],
            'fields.admin' => ['sometimes', 'nullable', 'array'],
            'fields.content_structure_override' => ['sometimes', 'nullable', 'string', 'max:64'],
            'translations' => ['sometimes', 'array', 'max:100'],
            'translations.*.id' => ['required', 'integer', 'min:1'],
            'translations.*.title' => ['sometimes', 'nullable', 'string'],
            'translations.*.content' => ['sometimes'],
            'translations.*.meta' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $page = $this->authorization->page($invocation, 'update');
        $this->translationIds($invocation, $page);
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $page = $this->authorization->page($invocation, 'update');
        $draft = $this->scratchDraft($invocation, $page);

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'preview',
            tool: $invocation->tool,
            data: [
                'page_id' => $page->id,
                'locale' => $this->locale($invocation),
                'before' => $draft->payload ?? [],
                'after' => $invocation->payload,
                'publication_impact' => 'unchanged_until_editor_save',
            ],
        );
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $page = $this->authorization->page($invocation, 'update');
        $result = SavePageEditorScratchDraftAction::run(new PageEditorScratchDraftInputData(
            page: $page,
            user: $invocation->user,
            locale: $this->locale($invocation),
            payload: $invocation->payload,
        ));

        if ($result->status !== PageEditorScratchDraftStatus::Saved) {
            throw ValidationException::withMessages(['draft' => __('capell-admin::agent.draft_not_saved')]);
        }

        return new AgentAdminToolResultData(
            ok: true,
            mode: 'executed',
            tool: $invocation->tool,
            data: [
                'page_id' => $page->id,
                'saved' => true,
                'locale' => $this->locale($invocation),
                'scratch_saved' => true,
            ],
        );
    }

    /** @return list<int> */
    private function translationIds(AgentAdminToolInvocationData $invocation, Page $page): array
    {
        $rows = $invocation->payload['translations'] ?? [];

        if (! is_array($rows)) {
            return [];
        }

        $translationIds = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw ValidationException::withMessages(['translations' => __('capell-admin::agent.translations_invalid')]);
            }

            if (! $page->translations()->whereKey($row['id'] ?? null)->exists()) {
                throw ValidationException::withMessages(['translations' => __('capell-admin::agent.translation_out_of_scope')]);
            }

            $translationIds[] = (int) $row['id'];
        }

        return $translationIds;
    }

    private function locale(AgentAdminToolInvocationData $invocation): string
    {
        return (string) ($invocation->payload['locale'] ?? app()->getLocale());
    }

    private function scratchDraft(AgentAdminToolInvocationData $invocation, Page $page): ?EditorScratchDraft
    {
        return EditorScratchDraft::query()
            ->forEditor($invocation->user, $page, $this->locale($invocation), 'page-editor')
            ->first();
    }
}
