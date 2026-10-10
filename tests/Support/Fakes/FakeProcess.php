<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

use Closure;
use Override;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A Symfony Process that stands in for the requested command.
 *
 * It runs a tiny PHP script that prints the scripted output and exits with the
 * scripted code, so Symfony's own run(), wait(), output streaming and exit-code
 * handling all behave for real (those methods are final). The command the code
 * asked for is kept in $command; setEnv(), setTimeout() and their getters are
 * the real ones, so tests assert what the code configured.
 */
final class FakeProcess extends Process
{
    private bool $ran = false;

    /**
     * @param  list<string>  $command
     * @param  (Closure(self): void)|null  $onRun  receives this process before it runs, e.g. to simulate Composer editing files
     * @param  (Closure(): void)|null  $onSetEnv  runs before the environment is stored, e.g. to make setup throw
     */
    public function __construct(
        public readonly array $command,
        int $exitCode = 0,
        private readonly string $output = '',
        private readonly string $errorOutput = '',
        private readonly ?Closure $onRun = null,
        private readonly ?Throwable $throws = null,
        private readonly ?Closure $onSetEnv = null,
    ) {
        parent::__construct([
            PHP_BINARY,
            '-n',
            '-r',
            'fwrite(STDOUT, $argv[1]); fwrite(STDERR, $argv[2]); exit((int) $argv[3]);',
            $output,
            $errorOutput,
            (string) $exitCode,
        ]);
    }

    #[Override]
    public function start(?callable $callback = null, array $env = []): void
    {
        $this->ran = true;

        if ($this->onRun instanceof Closure) {
            ($this->onRun)($this);
        }

        if ($this->throws instanceof Throwable) {
            // A process that fails mid-run has usually streamed some output first.
            if ($callback !== null) {
                $callback(self::OUT, $this->output);
                $callback(self::ERR, $this->errorOutput);
            }

            throw $this->throws;
        }

        parent::start($callback, $env);
    }

    #[Override]
    public function setEnv(array $env): static
    {
        if ($this->onSetEnv instanceof Closure) {
            ($this->onSetEnv)();
        }

        return parent::setEnv($env);
    }

    public function hasRun(): bool
    {
        return $this->ran;
    }
}
