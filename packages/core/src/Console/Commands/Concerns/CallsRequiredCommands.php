<?php

declare(strict_types=1);

namespace Capell\Core\Console\Commands\Concerns;

use Illuminate\Console\Command;

/** @mixin Command */
trait CallsRequiredCommands
{
    /** @param array<string, mixed> $arguments */
    protected function callRequired(string $command, array $arguments = []): bool
    {
        $exitCode = $this->call($command, $arguments);

        if ($exitCode === Command::SUCCESS) {
            return true;
        }

        $this->error(__('capell::message.required_command_failed', [
            'command' => $command . (isset($arguments['--tag']) && is_string($arguments['--tag']) ? ' --tag=' . $arguments['--tag'] : ''),
            'exit_code' => $exitCode,
        ]));

        return false;
    }
}
