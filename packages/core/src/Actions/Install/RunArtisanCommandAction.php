<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class RunArtisanCommandAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(
        string $command,
        array $arguments = [],
        ?ProgressReporter $reporter = null,
        bool $silent = false,
    ): void {
        $result = CallArtisanCommandAction::run($command, $arguments);
        $exitCode = $result->exitCode;
        $output = $result->combinedOutput();

        if ($output !== '' && ! $silent) {
            $reporter?->report($output);
        }

        if ($exitCode === 0) {
            return;
        }

        if ($output !== '') {
            $reporter?->error($output);
        }

        throw new RuntimeException(
            __('capell-core::install.command.failed', ['command' => $command, 'exit_code' => $exitCode])
            . ($output !== '' ? "\n" . __('capell-core::install.command.output', ['output' => $output]) : ''),
        );
    }
}
