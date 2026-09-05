<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Agent;

use Capell\Admin\Actions\Publishing\BuildPublishReadinessAction;
use Capell\Admin\Actions\Publishing\PublishRecordAction;
use Capell\Admin\Actions\Publishing\ScheduleRecordPublishAction;
use Capell\Admin\Contracts\Agent\AgentAdminTool;
use Capell\Admin\Data\Agent\AgentAdminToolInvocationData;
use Capell\Admin\Data\Agent\AgentAdminToolResultData;
use Capell\Core\Actions\Properties\EvaluatePropertyCompletenessAction;
use Capell\Core\Actions\Publishing\EvaluatePublicationTransitionAction;
use Capell\Core\Data\Agent\AgentToolBindingData;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Capell\Core\Data\Publishing\PublicationTransitionRequestData;
use Capell\Core\Data\Publishing\PublicationTransitionResultData;
use Capell\Core\Enums\Agent\AgentToolBindingType;
use Capell\Core\Enums\Agent\AgentToolEffect;
use Capell\Core\Enums\Publishing\PublicationTransition;
use Capell\Core\Models\Page;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class AgentPagePublicationTool implements AgentAdminTool
{
    public function __construct(
        private AgentAdminAuthorization $authorization,
        private PublicationTransition $transition,
    ) {}

    public function definition(): AgentToolDefinitionData
    {
        $schedule = $this->transition === PublicationTransition::SchedulePublish;

        return new AgentToolDefinitionData(
            name: $schedule ? 'admin.page.publish.schedule' : 'admin.page.publish',
            description: (string) __($schedule
                ? 'capell-admin::agent.page_publish_schedule_description'
                : 'capell-admin::agent.page_publish_description'),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer', 'minimum' => 1],
                    'publish_at' => ['type' => 'string', 'format' => 'date-time'],
                ],
                'required' => $schedule ? ['page_id', 'publish_at'] : ['page_id'],
                'additionalProperties' => false,
            ],
            outputSchema: [
                'type' => 'object',
                'properties' => [
                    'page_id' => ['type' => 'integer'],
                    'transition' => ['type' => 'string'],
                    'outcome' => ['type' => 'string'],
                    'reason' => ['type' => 'string'],
                ],
                'required' => ['page_id', 'transition', 'outcome', 'reason'],
                'additionalProperties' => false,
            ],
            effect: AgentToolEffect::Write,
            binding: new AgentToolBindingData(AgentToolBindingType::Endpoint, AgentAdminEndpoint::path('page/publish')),
            ownerPackage: 'capell-app/admin',
        );
    }

    public function isAvailable(AgentAdminToolInvocationData $invocation): bool
    {
        return $this->authorization->canUpdatePages($invocation->user, $invocation->siteId);
    }

    public function authorize(AgentAdminToolInvocationData $invocation): void
    {
        $rules = ['page_id' => ['required', 'integer', 'min:1']];

        if ($this->transition === PublicationTransition::SchedulePublish) {
            $rules['publish_at'] = ['required', 'date'];
        }

        validator($invocation->payload, $rules)->validate();
        $this->authorization->page($invocation, 'update');

        if (! $invocation->user instanceof User) {
            throw new AuthorizationException((string) __('capell-admin::agent.publication_actor_invalid'));
        }

        if ($this->transition === PublicationTransition::SchedulePublish) {
            $this->requestedTime($invocation);
        }
    }

    public function preview(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $page = $this->authorization->page($invocation, 'update');
        $request = $this->request($page, $invocation);
        $transition = EvaluatePublicationTransitionAction::run($request);
        $readiness = BuildPublishReadinessAction::run($page, $request);
        $completeness = EvaluatePropertyCompletenessAction::run($page);
        $blocked = $completeness->blocksPublish();

        return $this->result(
            $invocation,
            $page,
            $transition,
            $readiness->blockingCheckIds,
            $completeness->missingPublishRequired,
            $blocked,
            'preview',
        );
    }

    public function execute(AgentAdminToolInvocationData $invocation): AgentAdminToolResultData
    {
        $page = $this->authorization->page($invocation, 'update');

        if (! $invocation->user instanceof User) {
            throw new AuthorizationException((string) __('capell-admin::agent.publication_actor_invalid'));
        }

        $result = $this->transition === PublicationTransition::SchedulePublish
            ? ScheduleRecordPublishAction::run($page, $invocation->user, $this->requestedTime($invocation))
            : PublishRecordAction::run($page, $invocation->user);

        return $this->result(
            $invocation,
            $page,
            $result,
            [],
            [],
            ! $result->changed() && $result->outcome->value !== 'already-correct',
            'executed',
        );
    }

    private function request(Page $page, AgentAdminToolInvocationData $invocation): PublicationTransitionRequestData
    {
        return new PublicationTransitionRequestData(
            record: $page,
            transition: $this->transition,
            actor: $invocation->user,
            now: CarbonImmutable::now(),
            requestedTime: $this->transition === PublicationTransition::SchedulePublish
                ? $this->requestedTime($invocation)
                : null,
        );
    }

    private function requestedTime(AgentAdminToolInvocationData $invocation): CarbonImmutable
    {
        try {
            $requested = CarbonImmutable::parse((string) ($invocation->payload['publish_at'] ?? ''));
        } catch (Throwable) {
            throw ValidationException::withMessages(['publish_at' => __('capell-admin::agent.publish_at_invalid')]);
        }

        if (! $requested->isFuture()) {
            throw ValidationException::withMessages(['publish_at' => __('capell-admin::agent.publish_at_future')]);
        }

        return $requested;
    }

    /**
     * @param  list<string>  $blockingChecks
     * @param  list<string>  $missingPublish
     */
    private function result(
        AgentAdminToolInvocationData $invocation,
        Page $page,
        PublicationTransitionResultData $transition,
        array $blockingChecks,
        array $missingPublish,
        bool $blocked,
        string $mode,
    ): AgentAdminToolResultData {
        return new AgentAdminToolResultData(
            ok: ! $blocked && in_array($transition->outcome->value, ['changed', 'already-correct'], true),
            mode: $mode,
            tool: $invocation->tool,
            data: [
                'page_id' => $page->id,
                'transition' => $this->transition->value,
                'outcome' => $transition->outcome->value,
                'reason' => $transition->reasonKey,
                'before_state' => $transition->beforeState->value,
                'after_state' => $transition->afterState->value,
                // `PublishNow` evaluates against the current clock. Keep the
                // confirmation preview stable while still describing the
                // requested transition; the executed response contains the
                // persisted timestamp.
                'visible_from' => $mode === 'preview' && $this->transition === PublicationTransition::PublishNow
                    ? null
                    : $transition->visibleFrom?->toIso8601String(),
                'visible_until' => $transition->visibleUntil?->toIso8601String(),
                'blocking_checks' => $blockingChecks,
                'missing_publish_required' => $missingPublish,
            ],
            message: $blocked ? (string) __('capell-admin::agent.publication_blocked') : null,
        );
    }
}
