<?php

declare(strict_types=1);

namespace Capell\Core\Actions\RuntimeRefresh;

use Capell\Core\Data\RuntimeRefresh\RuntimeRefreshStageResultData;
use Illuminate\Contracts\Console\Kernel;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

class RunArtisanRuntimeRefreshStageAction
{
    use AsObject;

    public function __construct(private readonly Kernel $artisan) {}

    public function handle(string $key, string $label, string $command): RuntimeRefreshStageResultData
    {
        try {
            $exitCode = $this->artisan->call($command, ['--no-interaction' => true]);
            $output = trim($this->artisan->output());
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
