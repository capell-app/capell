<?php

declare(strict_types=1);

namespace Capell\Admin\Console\Commands;

use Capell\Admin\Actions\Media\PruneAdminUploadGrantsAction;
use Illuminate\Console\Command;

final class PruneAdminUploadGrantsCommand extends Command
{
    protected $description = 'Prune a bounded batch of expired admin upload ownership grants';

    protected $signature = 'capell:admin-prune-upload-grants';

    public function handle(): int
    {
        PruneAdminUploadGrantsAction::run();

        return self::SUCCESS;
    }
}
