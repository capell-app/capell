<?php

declare(strict_types=1);

namespace Capell\Core\Support\Process;

use Symfony\Component\Process\Process;

interface ProcessFactoryInterface
{
    /**
     * Create a new Symfony Process instance.
     *
     * @param  list<string>|string  $command
     * @param  array<string, string>|null  $environment
     */
    public function make(array|string $command, ?string $cwd = null, ?array $environment = null): Process;
}
