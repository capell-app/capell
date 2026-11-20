<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs PHPStan over a rule fixture the way a rule test needs it.
 *
 * - `--debug` analyses in-process: a parallel worker that crashes or times out
 *   on a loaded host is reported as a general error, not a file message, and a
 *   test reading only file messages then sees "nothing reported".
 * - General errors are surfaced, so an analyser failure fails the test with its
 *   cause instead of masquerading as a rule that reported nothing.
 * - The report is always the standard JSON format (`files`/`errors`): PHPStan
 *   switches to an agent format when its AgentDetector sees one of
 *   AGENT_VARIABLES, so they are cleared. The agent shape (`error_details`) is
 *   still read in case a later PHPStan detects an agent another way.
 */
final readonly class PhpStanFixtureAnalysis
{
    /** Hang ceiling only: a slow, loaded host must never fail the analysis. */
    private const int TIMEOUT_SECONDS = 600;

    /** The variables PHPStan's AgentDetector reads (phpstan/phpstan 2.x). */
    private const array AGENT_VARIABLES = ['AI_AGENT', 'AMP_CURRENT_THREAD_ID', 'AUGMENT_AGENT', 'CLAUDE_CODE', 'CLAUDECODE', 'CODEX_SANDBOX', 'CODEX_THREAD_ID', 'CURSOR_AGENT', 'CURSOR_TRACE_ID', 'GEMINI_CLI', 'OPENCODE', 'OPENCODE_CLIENT', 'REPL_ID'];

    /** @param array<string, mixed> $result */
    private function __construct(
        public int $exitCode,
        private array $result,
        private string $output,
        private string $errorOutput,
    ) {}

    public static function run(string $root, string $configuration, string $path, ?string $autoloadFile = null): self
    {
        $command = [PHP_BINARY, $root . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--debug', '--memory-limit=1G', '--error-format=json', '--configuration=' . $configuration];

        if ($autoloadFile !== null) {
            $command[] = '--autoload-file=' . $autoloadFile;
        }

        $command[] = $path;
        // false unsets a variable for the child process.
        $process = new Process($command, $root, array_fill_keys(self::AGENT_VARIABLES, false), timeout: self::TIMEOUT_SECONDS);
        $process->run();

        // In the standard format `--debug` prints each analysed path first; the report is the last line.
        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
        $report = (string) end($lines);

        try {
            $result = json_decode($report, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('PHPStan did not print JSON (exit ' . $process->getExitCode() . "):\n" . self::excerpt($process->getOutput() . $process->getErrorOutput()), $jsonException->getCode(), previous: $jsonException);
        }

        if (! is_array($result)) {
            throw new RuntimeException('PHPStan printed JSON that is not an object: ' . self::excerpt($process->getOutput()));
        }

        // Bootstrap/configuration failures can be wrapped as raw CLI output,
        // without an analysis report. They must never look like zero findings.
        if ($process->getExitCode() !== 0 && isset($result['raw'])) {
            throw new RuntimeException('PHPStan could not analyse the fixture (exit ' . $process->getExitCode() . "):\n" . self::excerpt($process->getOutput() . $process->getErrorOutput()));
        }

        /** @var array<string, mixed> $result */
        return new self((int) $process->getExitCode(), $result, $process->getOutput(), $process->getErrorOutput());
    }

    /** @return list<array<string, mixed>> */
    public function messagesFor(string $file): array
    {
        $files = $this->keyedByPath($this->section('files'));
        $details = $this->keyedByPath($this->section('error_details'));
        $path = $this->path($file);
        $messages = is_array($files[$path]['messages'] ?? null) ? $files[$path]['messages'] : ($details[$path] ?? []);

        return array_values(array_filter(is_array($messages) ? $messages : [], is_array(...)));
    }

    /** @return list<string> Messages reported against `identifier`, for one file. */
    public function messagesWithIdentifier(string $file, string $identifier): array
    {
        return array_values(array_map(
            static fn (array $message): string => (string) ($message['message'] ?? ''),
            array_filter($this->messagesFor($file), static fn (array $message): bool => ($message['identifier'] ?? null) === $identifier),
        ));
    }

    /** @return array<string, mixed> Every file's messages, in either output format. */
    public function fileMessages(): array
    {
        return $this->section('files') + $this->section('error_details');
    }

    /**
     * Analyser-level failures not tied to a file (a crashed worker, a bad
     * configuration, an unmatched ignore pattern), listed under `errors`.
     *
     * @return list<string>
     */
    public function generalErrors(): array
    {
        $errors = $this->result['errors'] ?? [];

        return is_array($errors) ? array_values(array_map(strval(...), array_filter($errors, is_scalar(...)))) : [];
    }

    /** What to print when an assertion fails, so the cause is in the report. */
    public function diagnostic(): string
    {
        return 'PHPStan exit ' . $this->exitCode . "\n" . self::excerpt($this->output . "\n" . $this->errorOutput);
    }

    private static function excerpt(string $text): string
    {
        return mb_strlen($text) > 4000 ? mb_substr($text, 0, 4000) . "\n… (truncated)" : $text;
    }

    /**
     * PHPStan reports each file under its normalised path. A caller's path can
     * differ, for example "/var/tmp//Consumer.php" when TMPDIR is unset on macOS
     * (sys_get_temp_dir() then returns "/var/tmp/"), so both sides are resolved.
     */
    private function path(string $file): string
    {
        $resolved = realpath($file);

        return $resolved !== false ? $resolved : (string) preg_replace('~/{2,}~', '/', $file);
    }

    /**
     * @param  array<array-key, mixed>  $section
     * @return array<string, mixed>
     */
    private function keyedByPath(array $section): array
    {
        $keyed = [];

        foreach ($section as $file => $entry) {
            $keyed[$this->path((string) $file)] = $entry;
        }

        return $keyed;
    }

    /** @return array<array-key, mixed> */
    private function section(string $key): array
    {
        $section = $this->result[$key] ?? null;

        return is_array($section) ? $section : [];
    }
}
