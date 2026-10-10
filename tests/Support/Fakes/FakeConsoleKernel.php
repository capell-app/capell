<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

use Illuminate\Contracts\Console\Kernel;
use LogicException;
use Throwable;

/**
 * Records Artisan calls and answers with scripted exit codes, output or exceptions.
 *
 * Bind it with FakeConsoleKernel::bind(); the Artisan facade then resolves it.
 * Commands without a scripted result exit 0 with no output.
 */
final class FakeConsoleKernel implements Kernel
{
    /** @var list<string> */
    public array $calls = [];

    /** Number of times the code asked which commands are registered. */
    public int $availabilityChecks = 0;

    /** @var array<string, int|Throwable> */
    private array $results = [];

    /** @var array<string, string> */
    private array $outputs = [];

    private string $lastOutput = '';

    /** @param  array<string, mixed>  $commands  the commands all() reports as registered */
    public function __construct(private readonly array $commands = []) {}

    /** @param  array<string, mixed>  $commands */
    public static function bind(array $commands = []): self
    {
        $kernel = new self($commands);
        app()->instance(Kernel::class, $kernel);

        return $kernel;
    }

    public function returns(string $command, int|Throwable $result, string $output = ''): self
    {
        $this->results[$command] = $result;
        $this->outputs[$command] = $output;

        return $this;
    }

    public function bootstrap(): void {}

    public function handle($input, $output = null): int
    {
        return 0;
    }

    public function call($command, array $parameters = [], $outputBuffer = null): int
    {
        $this->calls[] = (string) $command;
        $this->lastOutput = $this->outputs[$command] ?? '';
        $result = $this->results[$command] ?? 0;

        throw_if($result instanceof Throwable, $result);

        return $result;
    }

    public function queue($command, array $parameters = []): never
    {
        throw new LogicException('FakeConsoleKernel does not support queued commands.');
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $this->availabilityChecks++;

        return $this->commands;
    }

    public function output(): string
    {
        return $this->lastOutput;
    }

    public function terminate($input, $status): void {}
}
