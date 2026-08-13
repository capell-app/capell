<?php

declare(strict_types=1);

namespace Capell\Core\Actions\EditorScratchDrafts;

use Capell\Core\Models\EditorScratchDraft;
use Carbon\CarbonImmutable;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class PruneEditorScratchDraftsAction
{
    use AsFake;
    use AsObject;

    public function handle(?CarbonImmutable $now = null): int
    {
        return EditorScratchDraft::query()
            ->where('expires_at', '<=', $now ?? CarbonImmutable::now('UTC'))
            ->delete();
    }
}
