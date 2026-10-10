<?php

declare(strict_types=1);

namespace Capell\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class CommandFixture
{
    public readonly ScriptFixture $files;

    public function __construct()
    {
        $this->files = new ScriptFixture;
        // Never let a script fixture fall through to shared runtimes or remote services.
        foreach (['git', 'docker', 'docker-compose', 'composer', 'npm', 'npx', 'curl', 'gh'] as $command) {
            $this->fake($command);
        }
    }

    public function fake(string $command, string $body = ''): void
    {
        $recorder = <<<'PHP'
            $record = ['command' => basename($argv[0]), 'cwd' => getcwd(), 'arguments' => array_slice($argv, 1)];
            file_put_contents(getenv('COMMAND_FIXTURE_LOG'), json_encode($record, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
            PHP;
        $this->files->write('bin/' . $command, '#!' . PHP_BINARY . "\n<?php\n" . $recorder . "\n" . $body);
        chmod($this->files->root . '/bin/' . $command, 0755);
    }

    /** @param list<string> $arguments
     * @param  array<string, string|false>  $environment
     */
    public function run(array $arguments, array $environment = [], ?string $directory = null): Process
    {
        $process = new Process($arguments, $directory ?? $this->files->root, array_replace([
            'PATH' => $this->files->root . '/bin:' . dirname(PHP_BINARY) . ':' . getenv('PATH'),
            'COMMAND_FIXTURE_LOG' => $this->files->root . '/commands.jsonl',
            'CAPELL_NO_RELEASE_LOCK' => '1',
        ], $environment));
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    /** @return list<array{command: string, cwd: string, arguments: list<string>}> */
    public function calls(?string $command = null): array
    {
        $path = $this->files->root . '/commands.jsonl';
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        throw_unless(is_array($lines), RuntimeException::class, 'Unable to read command fixture outcomes.');
        $calls = [];
        foreach ($lines as $line) {
            /** @var array{command: string, cwd: string, arguments: list<string>} $call */
            $call = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if ($command === null || $call['command'] === $command) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    public function close(): void
    {
        $this->files->close();
    }
}
