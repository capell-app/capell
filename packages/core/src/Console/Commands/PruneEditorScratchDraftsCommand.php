<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\EditorScratchDrafts\PruneEditorScratchDraftsAction;
use Illuminate\Console\Command;
use Throwable;

final class PruneEditorScratchDraftsCommand extends Command
{
    protected $signature = 'capell:editor-scratch-drafts:prune';

    protected $description = 'Prune expired per-user editor recovery drafts.';

    public function handle(PruneEditorScratchDraftsAction $action): int
    {
        try {
            $count = $action->handle();
            $this->info(sprintf('Pruned %d editor recovery draft row(s).', $count));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
