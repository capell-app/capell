<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Agent;

use Capell\Admin\Support\Agent\AgentAdminAuthorization;
use Capell\Admin\Support\Agent\AgentAdminToolInvocationService;
use Capell\Core\Data\Agent\AgentToolDefinitionData;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class AgentAdminToolController
{
    public function __construct(
        private AgentAdminToolInvocationService $tools,
        private AgentAdminAuthorization $authorization,
    ) {}

    public function definitions(Request $request): JsonResponse
    {
        $user = $this->user();
        $siteId = $this->siteId($request, $user);

        return response()->json([
            'capellAgentSchema' => 1,
            'tools' => array_map(static fn (AgentToolDefinitionData $definition): array => $definition->toArray(), $this->tools->definitions($user, $siteId)),
        ]);
    }

    public function invoke(Request $request): JsonResponse
    {
        $user = $this->user();
        $siteId = $this->siteId($request, $user);
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $input = $request->validate([
            'tool' => ['required', 'string', 'max:191'],
            'payload' => ['sometimes', 'array'],
            'confirmation_token' => ['sometimes', 'nullable', 'string', 'size:64'],
        ]);

        $result = $this->tools->invoke(
            toolName: $input['tool'],
            payload: $input['payload'] ?? [],
            user: $user,
            siteId: $siteId,
            confirmationToken: $input['confirmation_token'] ?? null,
            sessionId: $sessionId,
        );

        return response()->json($result->toArray(), $result->ok ? 200 : 422);
    }

    private function user(): Authenticatable
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof Authenticatable && $user instanceof FilamentUser, 403);
        abort_unless($user->canAccessPanel(Filament::getPanel('admin')), 403);

        return $user;
    }

    private function siteId(Request $request, Authenticatable $user): int
    {
        abort_if($request->has('site_id'), 422, __('capell-admin::agent.site_must_follow_session'));

        $siteId = (int) $request->session()->get('capell.current_site_id', 0);

        if ($siteId > 0) {
            $this->authorization->site($user, $siteId);

            return $siteId;
        }

        if (method_exists($user, 'getAssignedSiteIds')) {
            $assignedSiteIds = $user->getAssignedSiteIds();

            if ($assignedSiteIds->count() === 1) {
                $siteId = (int) $assignedSiteIds->first();
                $this->authorization->site($user, $siteId);

                return $siteId;
            }
        }

        abort(422, __('capell-admin::agent.active_site_required'));
    }
}
