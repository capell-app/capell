<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Contracts\ProgressReporter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

final class CacheProgressReporter implements ProgressReporter
{
    private const int CACHE_TTL = 7200;

    private const int MAX_LINE_BYTES = 8192;

    private const int MAX_OUTPUT_BYTES = 262144;

    public function __construct(
        private readonly string $installId,
        private readonly ?FileCacheStoreDirectory $fileCacheStoreDirectory = null,
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
        $line = mb_strcut($line, 0, self::MAX_LINE_BYTES, 'UTF-8');
        $entry = json_encode(['type' => $type, 'line' => $line, 'ts' => Date::now()->getTimestamp()]) . "\n";
        $existing = Cache::get($this->outputKey(), '');
        $output = $existing . $entry;

        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            $output = substr($output, -self::MAX_OUTPUT_BYTES);
            $firstNewlinePosition = strpos($output, "\n");

            if ($firstNewlinePosition !== false) {
                $output = substr($output, $firstNewlinePosition + 1);
            }
        }

        $this->put($this->outputKey(), $output);
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
