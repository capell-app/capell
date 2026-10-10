<?php

declare(strict_types=1);

namespace Capell\Tests\Support\Fakes;

use Capell\Core\Support\Process\ProcessFactoryInterface;
use Closure;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * Records every command the code asks for and answers with scripted results.
 *
 * Results are consumed in order; once the queue is empty every further command
 * gets the default result. Bind it with FakeProcessFactory::bind().
 */
final class FakeProcessFactory implements ProcessFactoryInterface
{
    /** @var list<FakeProcess> */
    public array $processes = [];

    /**
     * Every command requested, including any whose creation was made to throw.
     *
     * @var list<list<string>>
     */
    private array $requested = [];

    /** @var list<array{exitCode: int, output: string, errorOutput: string, onRun: (Closure(FakeProcess): void)|null, throws: Throwable|null, onMake: (Closure(): void)|null, onSetEnv: (Closure(): void)|null}> */
    private array $queue = [];

    /** @var array{exitCode: int, output: string, errorOutput: string, onRun: (Closure(FakeProcess): void)|null, throws: Throwable|null, onMake: (Closure(): void)|null, onSetEnv: (Closure(): void)|null} */
    private array $default = ['exitCode' => 0, 'output' => '', 'errorOutput' => '', 'onRun' => null, 'throws' => null, 'onMake' => null, 'onSetEnv' => null];

    /**
     * Expected commands. Once any is registered, a command matching none of them
     * fails immediately, so a test can never fall through to real Composer.
     *
     * @var list<array{matcher: Closure(list<string>, ?string, ?array<string, string|false>): bool, times: int|null, seen: int}>
     */
    private array $expectations = [];

    /** @var list<string> */
    private array $unexpected = [];

    private static ?self $bound = null;

    public static function bind(): self
    {
        $factory = new self;
        app()->instance(ProcessFactoryInterface::class, $factory);

        return self::$bound = $factory;
    }

    /** Verify and forget the factory bound most recently; call from afterEach. */
    public static function verifyBound(): void
    {
        $factory = self::$bound;
        self::$bound = null;
        $factory?->verify();
    }

    /**
     * Expect a command, by exact argv or matcher, a given number of times (null for any).
     *
     * @param  list<string>|(Closure(list<string>, ?string, ?array<string, string|false>): bool)  $command
     */
    public function expect(array|Closure $command, ?int $times = 1): self
    {
        $matcher = $command instanceof Closure
            ? $command
            : static fn (array $argv): bool => $argv === $command;

        $this->expectations[] = ['matcher' => $matcher, 'times' => $times, 'seen' => 0];

        return $this;
    }

    public function verify(): void
    {
        // The code under test may catch the failure thrown from make(), so report it here too.
        Assert::assertSame([], $this->unexpected, 'Unexpected process commands were requested.');

        foreach ($this->expectations as $index => $expectation) {
            if ($expectation['times'] !== null) {
                Assert::assertSame(
                    $expectation['times'],
                    $expectation['seen'],
                    sprintf('Expected command #%d to run %d time(s); it ran %d.', $index, $expectation['times'], $expectation['seen']),
                );
            }
        }
    }

    /**
     * Queue the result for the next command.
     *
     * @param  (Closure(FakeProcess): void)|null  $onRun  runs when the process runs, e.g. to simulate Composer editing files
     * @param  (Closure(): void)|null  $onMake  runs when the factory creates the process, e.g. to make creation throw
     * @param  (Closure(): void)|null  $onSetEnv  runs when the code configures the environment, e.g. to make setup throw
     */
    public function push(
        int $exitCode = 0,
        string $output = '',
        string $errorOutput = '',
        ?Closure $onRun = null,
        ?Throwable $throws = null,
        ?Closure $onMake = null,
        ?Closure $onSetEnv = null,
    ): self {
        $this->queue[] = ['exitCode' => $exitCode, 'output' => $output, 'errorOutput' => $errorOutput, 'onRun' => $onRun, 'throws' => $throws, 'onMake' => $onMake, 'onSetEnv' => $onSetEnv];

        return $this;
    }

    /** Set the result for every command once the queue is empty. */
    public function byDefault(int $exitCode = 0, string $output = '', string $errorOutput = ''): self
    {
        $this->default = ['exitCode' => $exitCode, 'output' => $output, 'errorOutput' => $errorOutput, 'onRun' => null, 'throws' => null, 'onMake' => null, 'onSetEnv' => null];

        return $this;
    }

    public function make(array|string $command, ?string $cwd = null, ?array $environment = null): FakeProcess
    {
        $argv = is_array($command) ? array_values($command) : [$command];
        $this->requested[] = $argv;
        $this->matchExpectation($argv, $cwd, $environment);
        $result = array_shift($this->queue) ?? $this->default;

        if ($result['onMake'] instanceof Closure) {
            ($result['onMake'])();
        }

        $process = new FakeProcess(
            $argv,
            $result['exitCode'],
            $result['output'],
            $result['errorOutput'],
            $result['onRun'],
            $result['throws'],
            $result['onSetEnv'],
        );

        if ($cwd !== null) {
            $process->setWorkingDirectory($cwd);
        }

        if ($environment !== null) {
            $process->setEnv($environment);
        }

        $this->processes[] = $process;

        return $process;
    }

    /** @return list<list<string>> */
    public function commands(): array
    {
        return $this->requested;
    }

    /** @param  list<string>  $command */
    public function assertRan(array $command): void
    {
        Assert::assertContains($command, $this->requested, 'The expected command was not run.');
    }

    public function assertNothingRan(): void
    {
        Assert::assertSame([], $this->requested, 'Expected no commands to run.');
    }

    /**
     * @param  list<string>  $argv
     * @param  array<string, string|false>|null  $environment
     */
    private function matchExpectation(array $argv, ?string $cwd, ?array $environment): void
    {
        if ($this->expectations === []) {
            return;
        }

        foreach ($this->expectations as $index => $expectation) {
            if (($expectation['matcher'])($argv, $cwd, $environment)) {
                $this->expectations[$index]['seen']++;

                return;
            }
        }

        $this->unexpected[] = implode(' ', $argv);

        Assert::fail('Unexpected process command: ' . implode(' ', $argv));
    }
}
