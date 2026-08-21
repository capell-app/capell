<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageEditorScratchDraftResultData;
use Capell\Admin\Enums\PageEditorScratchDraftStatus;
use Capell\Core\Actions\EditorScratchDrafts\DiscardEditorScratchDraftAction;
use Capell\Core\Models\Page;
use Illuminate\Contracts\Auth\Authenticatable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DiscardPageEditorScratchDraftAction
{
    use AsFake;
    use AsObject;

    public function handle(
        Page $page,
        ?Authenticatable $user,
        string $locale,
    ): PageEditorScratchDraftResultData {
        if (! $user instanceof Authenticatable) {
            return new PageEditorScratchDraftResultData(PageEditorScratchDraftStatus::Unauthenticated);
        }

        $deleted = DiscardEditorScratchDraftAction::run(
            record: $page,
            user: $user,
            locale: $locale,
            context: 'page-editor',
        );

        return new PageEditorScratchDraftResultData(
            status: PageEditorScratchDraftStatus::Discarded,
            affectedRows: $deleted,
        );
    }
}
