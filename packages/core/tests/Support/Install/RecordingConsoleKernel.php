<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support\Install;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * Stands in for the console kernel so a test can assert whether a command ran
 * in the current process (call) and which commands were visible (all).
 *
 * The Testbench kernel is final, so the Artisan facade cannot be mocked.
 */
final class RecordingConsoleKernel implements ConsoleKernel
{
    public int $allCalls = 0;

    /** @var list<array{command: string, parameters: array<string, mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $commands */
    public function __construct(private readonly array $commands = [], private readonly int $exitCode = 0) {}

    /** @param array<string, mixed> $commands */
    public static function bind(array $commands = [], int $exitCode = 0): self
    {
        $kernel = new self($commands, $exitCode);

        app()->instance(ConsoleKernel::class, $kernel);
        Facade::clearResolvedInstance(ConsoleKernel::class);

        return $kernel;
    }

    public static function release(): void
    {
        Facade::clearResolvedInstance(ConsoleKernel::class);
    }

    public function bootstrap(): void {}

    public function handle($input, $output = null): int
    {
        return 0;
    }

    public function call($command, array $parameters = [], $outputBuffer = null): int
    {
        $this->calls[] = ['command' => $command, 'parameters' => $parameters];

        return $this->exitCode;
    }

    public function queue($command, array $parameters = []): PendingDispatch
    {
        throw new RuntimeException('The install flow should not queue commands.');
    }

    public function all(): array
    {
        $this->allCalls++;

        return $this->commands;
    }

    public function output(): string
    {
        return '';
    }

    public function terminate($input, $status): void {}
}
