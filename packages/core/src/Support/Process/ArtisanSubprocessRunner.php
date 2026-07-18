<?php

declare(strict_types=1);

namespace Capell\Core\Support\Process;

use Capell\Core\Support\Composer\ComposerProcessEnvironment;

final readonly class ArtisanSubprocessRunner
{
    public function __construct(private ProcessFactoryInterface $processFactory) {}

    /**
     * @param  list<string>  $arguments
     * @param  callable(string): void  $onLine
     */
    public function run(array $arguments, callable $onLine, ?float $timeout = 120): int
    {
        $memoryLimit = ini_get('memory_limit');
        $memoryLimit = is_string($memoryLimit) && $memoryLimit !== '' ? $memoryLimit : '512M';
        $process = $this->processFactory->make(
            [PHP_BINARY, '-d', "memory_limit={$memoryLimit}", 'artisan', ...$arguments],
            base_path(),
            ArtisanProcessEnvironment::prepare(ComposerProcessEnvironment::forInstall($_SERVER)),
        );
        $process->setTimeout($timeout);
        $process->run(function (string $type, string $buffer) use ($onLine): void {
            foreach (explode("\n", trim($buffer)) as $line) {
                if ($line !== '') {
                    $onLine($line);
                }
            }
        });

        return $process->getExitCode() ?? 1;
    }
}
