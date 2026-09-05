<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Enums\TranslatableType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

final readonly class AgentAdminToolInvocationService
{
    public function __construct(
        private AgentAdminToolRegistry $registry,
        private AgentAdminConfirmationStore $confirmations,
    ) {}

    /** @return list<AgentToolDefinitionData> */
    public function definitions(Authenticatable $user, int $siteId): array
    {
        return $this->registry->definitionsFor($user, $siteId);
    }

    /**
     * A write invocation returns a one-use confirmation token after previewing.
     * Supplying that token revalidates the permission and preview before write.
     *
     * @param  array<string, mixed>  $payload
     */
    public function invoke(
        string $toolName,
        array $payload,
        Authenticatable $user,
        int $siteId,
        ?string $confirmationToken = null,
        ?string $sessionId = null,
    ): AgentAdminToolResultData {
        $tool = $this->registry->tool($toolName);
        /** @var array{preview_fingerprint: string, payload: array<string, mixed>}|null $record */
        $record = null;

        if ($confirmationToken !== null) {
            $record = $this->confirmations->pull($confirmationToken, $user, $toolName, $siteId, $sessionId);
            $payload = $record['payload'];
        }

        $invocation = new AgentAdminToolInvocationData(
            tool: $toolName,
            payload: $payload,
            siteId: $siteId,
            user: $user,
            sessionId: $sessionId,
        );

        if ($tool->definition()->effect === AgentToolEffect::Read) {
            $tool->authorize($invocation);

            return $tool->execute($invocation);
        }

        if ($confirmationToken === null) {
            $tool->authorize($invocation);
            $preview = $tool->preview($invocation);

            if (! $preview->ok) {
                return new AgentAdminToolResultData(
                    ok: false,
                    mode: 'rejected',
                    tool: $toolName,
                    data: $preview->data,
                    message: $preview->message,
                );
            }

            $token = $this->confirmations->put($invocation, $preview->toArray());

            return new AgentAdminToolResultData(
                ok: true,
                mode: 'confirmation_required',
                tool: $toolName,
                data: $preview->data,
                confirmationToken: $token,
                message: (string) __('capell-admin::agent.confirmation_required'),
            );
        }

        return DB::transaction(function () use ($tool, $invocation, $toolName, $record): AgentAdminToolResultData {
            $this->lockWriteTargets($invocation);
            $tool->authorize($invocation);
            $preview = $tool->preview($invocation);

            if (! hash_equals($record['preview_fingerprint'], AgentAdminConfirmationStore::fingerprint($preview->toArray()))) {
                throw new AuthorizationException((string) __('capell-admin::agent.confirmation_changed'));
            }

            if (! $preview->ok) {
                return new AgentAdminToolResultData(
                    ok: false,
                    mode: 'rejected',
                    tool: $toolName,
                    data: $preview->data,
                    message: $preview->message,
                );
            }

            return $tool->execute($invocation);
        });
    }

    private function lockWriteTargets(AgentAdminToolInvocationData $invocation): void
    {
        if ($invocation->tool === 'admin.settings.write') {
            DB::table('settings')
                ->where('group', (string) ($invocation->payload['group'] ?? ''))
                ->lockForUpdate()
                ->get();

            return;
        }

        if ($invocation->tool === 'admin.structure.write') {
            $resource = (string) ($invocation->payload['resource'] ?? '');
            $id = $invocation->payload['id'] ?? null;

            if (($invocation->payload['operation'] ?? null) === 'update' && is_numeric($id)) {
                DB::table($resource === 'taxonomy' ? 'taxonomies' : 'property_sets')
                    ->where('id', (int) $id)
                    ->lockForUpdate()
                    ->first();
            }

            return;
        }

        if ($invocation->tool === 'admin.blueprint.write') {
            $id = $invocation->payload['id'] ?? null;

            if (is_numeric($id)) {
                DB::table('blueprints')
                    ->where('id', (int) $id)
                    ->lockForUpdate()
                    ->first();
            }

            return;
        }

        $pageId = $invocation->payload['page_id'] ?? null;

        if (! is_int($pageId) && ! is_numeric($pageId)) {
            return;
        }

        $pageId = (int) $pageId;

        if ($pageId < 1) {
            return;
        }

        DB::table('pages')
            ->where('id', $pageId)
            ->where('site_id', $invocation->siteId)
            ->lockForUpdate()
            ->first();

        DB::table('page_property_values')
            ->where('page_id', $pageId)
            ->where('site_id', $invocation->siteId)
            ->lockForUpdate()
            ->get();

        DB::table('translations')
            ->where('translatable_type', TranslatableType::Page->value)
            ->where('translatable_id', $pageId)
            ->lockForUpdate()
            ->get();

        DB::table('page_term')
            ->where('page_id', $pageId)
            ->lockForUpdate()
            ->get();

        DB::table('editor_scratch_drafts')
            ->where('user_id', $invocation->user->getAuthIdentifier())
            ->where('site_id', $invocation->siteId)
            ->where('record_type', TranslatableType::Page->value)
            ->where('record_id', $pageId)
            ->where('context', 'page-editor')
            ->where('locale', (string) ($invocation->payload['locale'] ?? app()->getLocale()))
            ->lockForUpdate()
            ->first();
    }
}
