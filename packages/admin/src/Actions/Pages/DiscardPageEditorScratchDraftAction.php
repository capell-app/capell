<?php

declare(strict_types=1);

namespace Capell\Admin\Actions\Pages;

use Capell\Admin\Data\Pages\PageEditorScratchDraftResultData;
use Capell\Admin\Enums\PageEditorScratchDraftStatus;
use Capell\Core\Actions\EditorScratchDrafts\DiscardEditorScratchDraftAction;
use Capell\Core\Contracts\Pageable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DiscardPageEditorScratchDraftAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  Model&Pageable<Model>  $page
     */
    public function handle(
        Model&Pageable $page,
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
