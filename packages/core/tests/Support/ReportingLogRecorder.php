<?php

declare(strict_types=1);

namespace Capell\Core\Tests\Support;

use Monolog\Level;

final readonly class ReportingLogRecord
{
    public function __construct(public Level $level, public string $message) {}
}

final readonly class ReportingLogRecorder
{
    public function __construct(public string $path) {}

    /** @return list<ReportingLogRecord> */
    public function getRecords(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $contents = file_get_contents($this->path);
        if (! is_string($contents)) {
            return [];
        }

        $records = [];
        foreach (array_filter(explode("\n", $contents)) as $line) {
            if (preg_match('/\.([A-Z]+):\s(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            $message = preg_replace('/\s+\[\]\s+\[\]\s*$/', '', $matches[2]);
            $records[] = new ReportingLogRecord(Level::fromName($matches[1]), is_string($message) ? $message : $matches[2]);
        }

        return $records;
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
