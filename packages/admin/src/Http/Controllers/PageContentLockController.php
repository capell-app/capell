<?php

declare(strict_types=1);

namespace Capell\Admin\Http\Controllers;

use Capell\Admin\Actions\Pages\ResolveContentLockRecordAction;
use Capell\Core\Actions\ContentLocks\AcquireContentLockAction;
use Capell\Core\Actions\ContentLocks\ReleaseContentLockAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

class PageContentLockController extends Controller
{
    public function heartbeat(Request $request, string $page): JsonResponse
    {
        $type = $request->query('type', 'page');
        abort_unless(is_string($type), 404);
        $record = ResolveContentLockRecordAction::run($type, $page);
        abort_if($record === null, 404);
        Gate::authorize('update', $record);

        $user = $this->authenticatedUser($request);
        $lock = AcquireContentLockAction::run($record, $user);

        if (! $lock->isOwnedBy($user)) {
            return response()->json([
                'message' => __('capell-admin::message.content_lock_conflict'),
            ], 409);
        }

        return response()->json([
            'expires_at' => $lock->expires_at->toIso8601String(),
        ]);
    }

    public function release(Request $request, string $page): JsonResponse
    {
        $type = $request->query('type', 'page');
        abort_unless(is_string($type), 404);
        $record = ResolveContentLockRecordAction::run($type, $page);
        abort_if($record === null, 404);
        Gate::authorize('update', $record);

        ReleaseContentLockAction::run($record, $this->authenticatedUser($request));

        return response()->json(['released' => true]);
    }

    private function authenticatedUser(Request $request): Authenticatable
    {
        $user = $request->user();

        abort_unless($user instanceof Authenticatable, 403);

        return $user;
    }
}
