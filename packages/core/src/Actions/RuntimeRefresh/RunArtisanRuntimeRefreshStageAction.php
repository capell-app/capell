<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

class RunArtisanRuntimeRefreshStageAction
{
    use AsFake;
    use AsObject;

    public function __construct(private readonly Kernel $artisan) {}

    public function handle(string $key, string $label, string $command): RuntimeRefreshStageResultData
    {
        try {
            $exitCode = $this->artisan->call($command, ['--no-interaction' => true]);
            $output = trim($this->artisan->output());

            // The command ignores filesystem deletion results, so verify its persisted output.
            if ($exitCode === 0 && $command === 'view:clear') {
                $path = config('view.compiled');
                if (is_string($path) && resolve(Filesystem::class)->glob($path . '/*') !== []) {
                    throw new RuntimeException(__('capell::runtime-refresh.cache_refresh_required') . ' ' . __('capell::runtime-refresh.cache_removal_failed', ['path' => $path]));
                }
            }
        } catch (Throwable $throwable) {
            return new RuntimeRefreshStageResultData(
                key: $key,
                label: $label,
                passed: false,
                message: $throwable->getMessage(),
            );
        }

        return new RuntimeRefreshStageResultData(
            key: $key,
            label: $label,
            passed: $exitCode === 0,
            message: $output !== ''
                ? $output
                : sprintf('Command [%s] exited with status %d.', $command, $exitCode),
        );
    }
}
