<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Throwable;

final class CacheProgressReporter implements ProgressReporter
{
    private const int CACHE_TTL = 7200;

    private const int MAX_LINE_BYTES = 8192;

    private const int MAX_OUTPUT_BYTES = 262144;

    /** @param list<string> $redactionSecrets */
    public function __construct(
        private readonly string $installId,
        private readonly ?FileCacheStoreDirectory $fileCacheStoreDirectory = null,
        private readonly array $redactionSecrets = [],
    ) {}

    public function step(string $label): void
    {
        if ($this->hasOutput()) {
            $this->append('separator', '');
        }

        $this->append('step', $label);
    }

    public function report(string $line): void
    {
        $this->append('info', $line);
    }

    public function error(string $line): void
    {
        $this->append('error', $line);
    }

    public function markRunning(): void
    {
        $this->put($this->statusKey(), 'running');
    }

    public function markComplete(): void
    {
        $this->put($this->statusKey(), 'complete');
    }

    public function markFailed(): void
    {
        $this->put($this->statusKey(), 'failed');
    }

    private function append(string $type, string $line): void
    {
        Cache::lock(sprintf('capell.install.%s.output-lock', $this->installId), 30)->block(5, function () use ($type, $line): void {
            $line = (string) InstallReportRedactor::redact($line, $this->redactionSecrets);
            $entry = ['type' => $type, 'ts' => Date::now()->getTimestamp()];
            $reportKey = sprintf('capell.install.%s.terminal_report', $this->installId);
            $report = Cache::get($reportKey);
            if (is_array($report)) {
                try {
                    $encrypted = Cache::get(sprintf('capell.install.%s.report_redaction', $this->installId));
                    throw_unless(is_string($encrypted), RuntimeException::class, 'Redaction context unavailable');
                    $secrets = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
                    throw_unless(is_array($secrets) && array_is_list($secrets) && array_all($secrets, fn (mixed $secret): bool => is_string($secret)), RuntimeException::class, 'Invalid redaction context');
                    $entry['line'] = mb_strcut((string) InstallReportRedactor::redact($line, [...$secrets, ...$this->redactionSecrets]), 0, self::MAX_LINE_BYTES, 'UTF-8');
                } catch (Throwable) {
                    // Historical snapshots have no dictionary. Never publish raw late output.
                    $entry['line'] = '[Late diagnostic omitted: redaction context unavailable]';
                }

                $lines = [...($report['lines'] ?? []), $entry];
                $bytes = array_sum(array_map(fn (mixed $item): int => strlen(json_encode($item, JSON_THROW_ON_ERROR)) + 1, $lines));
                while ($bytes > self::MAX_OUTPUT_BYTES && count($lines) > 1) {
                    $bytes -= strlen(json_encode(array_shift($lines), JSON_THROW_ON_ERROR)) + 1;
                    $report['diagnostics']['omittedOutputLines'] = ($report['diagnostics']['omittedOutputLines'] ?? 0) + 1;
                }

                $report['lines'] = $lines;
                $this->put($reportKey, $report);

                return;
            }

            $entry['line'] = mb_strcut($line, 0, self::MAX_LINE_BYTES, 'UTF-8');
            $existing = Cache::get($this->outputKey(), '');
            $output = $existing . json_encode($entry) . "\n";

            if (strlen($output) > self::MAX_OUTPUT_BYTES) {
                $output = substr($output, -self::MAX_OUTPUT_BYTES);
                $firstNewlinePosition = strpos($output, "\n");

                if ($firstNewlinePosition !== false) {
                    $output = substr($output, $firstNewlinePosition + 1);
                }
            }

            $this->put($this->outputKey(), $output);
        });
    }

    private function hasOutput(): bool
    {
        return trim((string) Cache::get($this->outputKey(), '')) !== '';
    }

    private function put(string $key, mixed $value): bool
    {
        return $this->fileCacheStoreDirectory()->put($key, $value, self::CACHE_TTL);
    }

    private function fileCacheStoreDirectory(): FileCacheStoreDirectory
    {
        return $this->fileCacheStoreDirectory ?? resolve(FileCacheStoreDirectory::class);
    }

    private function outputKey(): string
    {
        return sprintf('capell.install.%s.output', $this->installId);
    }

    private function statusKey(): string
    {
        return sprintf('capell.install.%s.status', $this->installId);
    }
}
