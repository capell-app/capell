<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers;

use Capell\Core\Actions\ContentLocks\AcquireContentLockAction;
use Capell\Core\Actions\ContentLocks\ReleaseContentLockAction;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class PageContentLockController extends Controller
{
    public function heartbeat(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('update', $page);

        $user = $this->authenticatedUser($request);
        $lock = AcquireContentLockAction::run($page, $user);

        if (! $lock->isOwnedBy($user)) {
            return response()->json([
                'message' => __('capell-admin::message.content_lock_conflict'),
            ], 409);
        }

        return response()->json([
            'expires_at' => $lock->expires_at->toIso8601String(),
        ]);
    }

    public function release(Request $request, Page $page): JsonResponse
    {
        Gate::authorize('update', $page);

        ReleaseContentLockAction::run($page, $this->authenticatedUser($request));

        return response()->json(['released' => true]);
    }

    private function authenticatedUser(Request $request): Authenticatable
    {
        $user = $request->user();

        abort_unless($user instanceof Authenticatable, 403);

        return $user;
    }
}
