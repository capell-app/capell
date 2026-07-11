<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Artisan;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

final class RunArtisanCommandAction
{
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
        $exitCode = Artisan::call($command, $arguments);
        $output = trim(Artisan::output());

        if ($output !== '' && ! $silent) {
            $reporter?->report($output);
        }

        if ($exitCode === 0) {
            return;
        }

        if ($output !== '') {
            $reporter?->error($output);
        }

        throw new RuntimeException(sprintf(
            "Artisan command '%s' failed with exit code %d.%s",
            $command,
            $exitCode,
            $output !== '' ? "\nOutput: " . $output : '',
        ));
    }
}
