<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Backup\CreateBackupAction;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

final class CreateBackupCommand extends Command
{
    protected $signature = 'capell:backup:create
        {--database-only : Exclude configured media disks from the snapshot}';

    protected $description = 'Create a verified Capell database and media backup snapshot.';

    public function handle(CreateBackupAction $createBackup): int
    {
        try {
            $manifest = $createBackup->handle(databaseOnly: (bool) $this->option('database-only'));
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return CommandAlias::FAILURE;
        }

        $this->components->info('Backup snapshot created: ' . $manifest->snapshotId);
        $this->components->twoColumnDetail('Database bytes', number_format($manifest->database->bytes));
        $this->components->twoColumnDetail('Media files', number_format(count($manifest->media)));

        return CommandAlias::SUCCESS;
    }
}
