<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Support\Install\PanelProviderWriter;
use Capell\Core\Support\Patching\EnvFileEditor;
use Capell\Core\Support\Patching\FileBackup;
use Capell\Core\Support\Patching\FilePatchPlan;
use Capell\Core\Support\Patching\FilePatchReview;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

class ApplyFilePatchPlanAction
{
    use AsFake;
    use AsObject;

    /**
     * The callback records the backup before saving, including when saving fails.
     *
     * @param  callable(string, bool): void  $recordRecovery
     */
    public function handle(FilePatchPlan $plan, callable $recordRecovery, ?FilePatchReview $review = null): void
    {
        if ($plan->before === null) {
            $review?->assertMatches($plan);
            throw_if(file_exists($plan->path), RuntimeException::class, 'Patch target already exists.');
            throw_unless(is_dir(dirname($plan->path)) || @mkdir(dirname($plan->path), 0755, true), RuntimeException::class, 'Could not create patch directory.');
            $handle = @fopen($plan->path, 'x');
            throw_unless(is_resource($handle), RuntimeException::class, 'Could not create patch file.');
            $recordRecovery($plan->path, true);
            try {
                throw_unless(fwrite($handle, $plan->after) === strlen($plan->after), RuntimeException::class, 'Could not write patch file.');
            } finally {
                fclose($handle);
            }

            $review?->recordApplied($plan);

            return;
        }

        $source = @fopen($plan->path, 'rb');
        throw_unless(is_resource($source), RuntimeException::class, 'Could not read patch source.');
        try {
            throw_unless(flock($source, LOCK_EX), RuntimeException::class, 'Could not lock patch source.');
            $review?->assertMatches($plan);
            throw_unless(file_get_contents($plan->path) === $plan->before, RuntimeException::class, 'Patch source changed before it could be saved.');
            $backup = $plan->environment
                ? new EnvFileEditor($plan->path)->backup() . '/.env'
                : new FileBackup($plan->path)->create();
            $recordRecovery($backup, false);

            if ($plan->panelProvider) {
                resolve(PanelProviderWriter::class)->save($plan->path, $plan->before, $plan->after);
            } else {
                $written = @file_put_contents($plan->path, $plan->after);
                throw_unless($written === strlen($plan->after), RuntimeException::class, 'Failed to write patch file: ' . $plan->relativePath);
            }

            $review?->recordApplied($plan);
        } finally {
            flock($source, LOCK_UN);
            fclose($source);
        }

        clearstatcache(true, $plan->path);
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($plan->path, true);
        }
    }
}
