<?php

declare(strict_types=1);

namespace Capell\Core\Actions\EditorScratchDrafts;

use Capell\Core\Models\EditorScratchDraft;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class DiscardEditorScratchDraftAction
{
    use AsFake;
    use AsObject;

    public function handle(Model $record, Authenticatable $user, string $locale, string $context): int
    {
        return EditorScratchDraft::query()
            ->forEditor($user, $record, $locale, $context)
            ->delete();
    }
}
