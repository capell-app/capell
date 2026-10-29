<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands;

use Capell\Core\Actions\Backup\RestoreBackupAction;
use Capell\Core\Exceptions\Backup\ScratchRestoreFailedException;
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
        } catch (ScratchRestoreFailedException $exception) {
            $this->reportScratchFailure($exception);

            return CommandAlias::FAILURE;
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return CommandAlias::FAILURE;
        }

        $this->components->info('Scratch restore verified: ' . $result->snapshotId);
        $this->components->twoColumnDetail('Scratch database', $result->database);
        $this->components->twoColumnDetail('Media files', number_format($result->mediaFiles));

        return CommandAlias::SUCCESS;
    }

    private function reportScratchFailure(ScratchRestoreFailedException $exception): void
    {
        $failure = $exception->failure;

        $this->components->error($exception->getMessage());

        foreach ($failure->failedChecks as $check) {
            $this->components->bulletList([
                (string) __('capell-core::backup.restore_failed_check', ['label' => $check['label'], 'id' => $check['id'], 'message' => $check['message']]),
                ...($check['remediation'] !== null ? [(string) __('capell-core::backup.restore_failed_check_remedy', ['remedy' => $check['remediation']])] : []),
            ]);
        }

        $this->components->warn((string) __('capell-core::backup.restore_retained_targets'));
        $this->components->twoColumnDetail((string) __('capell-core::backup.restore_retained_database'), $failure->database);

        if ($failure->mediaDisk !== null) {
            $this->components->twoColumnDetail((string) __('capell-core::backup.restore_retained_media'), (string) __('capell-core::backup.restore_retained_media_value', [
                'restored' => $failure->mediaFilesRestored,
                'expected' => $failure->mediaFilesExpected,
                'disk' => $failure->mediaDisk,
                'prefix' => (string) $failure->mediaPrefix,
            ]));
        }

        $this->components->info((string) __('capell-core::backup.restore_retry'));
    }
}
