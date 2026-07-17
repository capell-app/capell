<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Backup\RestoreBackupAction;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Throwable;

final class RestoreBackupCommand extends Command
{
    protected $signature = 'capell:backup:restore
        {snapshot : Manifest-backed snapshot identifier}
        {scratch-database : New scratch database name}
        {--media-disk= : Non-live disk for restored media}
        {--media-prefix= : Empty non-live prefix for restored media}';

    protected $description = 'Restore a Capell snapshot into isolated scratch targets and run doctor verification.';

    public function handle(RestoreBackupAction $restoreBackup): int
    {
        try {
            $result = RestoreBackupAction::run(
                snapshotId: (string) $this->argument('snapshot'),
                scratchDatabase: (string) $this->argument('scratch-database'),
                mediaDisk: is_string($this->option('media-disk')) ? $this->option('media-disk') : null,
                mediaPrefix: is_string($this->option('media-prefix')) ? $this->option('media-prefix') : null,
            );
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return CommandAlias::FAILURE;
        }

        $this->components->info('Scratch restore verified: ' . $result->snapshotId);
        $this->components->twoColumnDetail('Scratch database', $result->database);
        $this->components->twoColumnDetail('Media files', number_format($result->mediaFiles));

        return CommandAlias::SUCCESS;
    }
}
