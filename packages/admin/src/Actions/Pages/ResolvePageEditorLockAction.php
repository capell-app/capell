<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageEditorLockRequestData;
use Capell\Admin\Data\Pages\PageEditorLockResultData;
use Capell\Admin\Enums\PageEditorLockOperation;
use Capell\Admin\Enums\PageEditorLockStatus;
use Capell\Core\Actions\ContentLocks\AcquireContentLockAction;
use Capell\Core\Actions\ContentLocks\FindConflictingContentLockAction;
use Capell\Core\Actions\ContentLocks\ForceContentLockAction;
use Capell\Core\Models\ContentLock;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class ResolvePageEditorLockAction
{
    use AsFake;
    use AsObject;

    public function handle(PageEditorLockRequestData $request): PageEditorLockResultData
    {
        if (! $request->user instanceof Authenticatable) {
            return new PageEditorLockResultData(PageEditorLockStatus::Unavailable);
        }

        return match ($request->operation) {
            PageEditorLockOperation::Inspect => $this->inspect($request),
            PageEditorLockOperation::Open => $this->open($request),
            PageEditorLockOperation::Save => $this->prepareSave($request),
            PageEditorLockOperation::TakeOver => $this->takeOver($request),
        };
    }

    private function inspect(PageEditorLockRequestData $request): PageEditorLockResultData
    {
        $lock = FindConflictingContentLockAction::run($request->record, $request->user);

        return $lock instanceof ContentLock
            ? new PageEditorLockResultData(PageEditorLockStatus::Conflict, $lock)
            : new PageEditorLockResultData(PageEditorLockStatus::Available);
    }

    private function open(PageEditorLockRequestData $request): PageEditorLockResultData
    {
        $lock = AcquireContentLockAction::run($request->record, $request->user);

        return $lock->isOwnedBy($request->user)
            ? new PageEditorLockResultData(PageEditorLockStatus::Owned, $lock)
            : new PageEditorLockResultData(PageEditorLockStatus::Conflict, $lock);
    }

    private function prepareSave(PageEditorLockRequestData $request): PageEditorLockResultData
    {
        $inspection = $this->inspect($request);

        return $inspection->isBlocked()
            ? $inspection
            : $this->open($request);
    }

    private function takeOver(PageEditorLockRequestData $request): PageEditorLockResultData
    {
        $lock = ForceContentLockAction::run($request->record, $request->user);

        return new PageEditorLockResultData(PageEditorLockStatus::Owned, $lock);
    }
}
