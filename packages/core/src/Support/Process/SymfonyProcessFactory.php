<?php

declare(strict_types=1);

namespace Capell\Core\Support\Process;

use Symfony\Component\Process\Process;

final class SymfonyProcessFactory implements ProcessFactoryInterface
{
    /**
     * @param  list<string>|string  $command
     * @param  array<string, string>|null  $environment
     */
    public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process
    {
        return is_string($command)
            ? Process::fromShellCommandline($command, $cwd, $environment)
            : new Process($command, $cwd, $environment);
    }
}
