<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageEditorLockRequestData;
use Capell\Admin\Data\Pages\PageEditorScratchDraftInputData;
use Capell\Admin\Data\Pages\PageEditorScratchDraftResultData;
use Capell\Admin\Enums\PageEditorLockOperation;
use Capell\Admin\Enums\PageEditorScratchDraftStatus;
use Capell\Core\Actions\EditorScratchDrafts\SaveEditorScratchDraftAction;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class SavePageEditorScratchDraftAction
{
    use AsFake;
    use AsObject;

    public function handle(PageEditorScratchDraftInputData $input): PageEditorScratchDraftResultData
    {
        if (! $input->user instanceof Authenticatable) {
            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Unauthenticated);
        }

        if (Gate::forUser($input->user)->denies('update', $input->page)) {
            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Unauthorized);
        }

        $lock = ResolvePageEditorLockAction::run(new PageEditorLockRequestData(
            record: $input->page,
            user: $input->user,
            operation: PageEditorLockOperation::Inspect,
        ));

        if ($lock->isBlocked()) {
            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Locked);
        }

        SaveEditorScratchDraftAction::run(
            record: $input->page,
            user: $input->user,
            locale: $input->locale,
            context: 'page-editor',
            payload: $input->payload,
        );

        return new PageEditorScratchDraftResultData(
            status: PageEditorScratchDraftStatus::Saved,
            affectedRows: 1,
        );
    }
}
