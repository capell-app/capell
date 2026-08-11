<?php

declare(strict_types=1);

namespace Capell\Core\Support\Health;

use Capell\Core\Contracts\Health\HealthCheck;
use Capell\Core\Data\Health\HealthCheckResultData;
use Capell\Core\Enums\Health\HealthSeverity;
use Capell\Core\Enums\Health\HealthStatus;
use Closure;
use InvalidArgumentException;
use RuntimeException;

final readonly class DiskCapacityHealthCheck implements HealthCheck
{
    /** @param (Closure(string): (int|false))|null $probe */
    public function __construct(private string $path, private int $minimumFreeBytes, private int $timeout = 10, private ?Closure $probe = null)
    {
        throw_if($path === '' || $minimumFreeBytes < 0, InvalidArgumentException::class, 'Disk health requires a path and a non-negative minimum capacity.');
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $value = (float) max(0, $bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf($unit === 0 ? '%.0f %s' : '%.1f %s', $value, $units[$unit]);
    }

    public function id(): string
    {
        return 'core.disk-capacity';
    }

    public function category(): string
    {
        return 'capacity';
    }

    public function timeoutSeconds(): int
    {
        return max(1, $this->timeout);
    }

    public function run(): HealthCheckResultData
    {
        $free = $this->probe instanceof Closure ? ($this->probe)($this->path) : disk_free_space($this->path);
        throw_if($free === false, RuntimeException::class, 'Disk capacity could not be determined for the configured storage path.');
        $freeBytes = (int) $free;
        $shortfall = max(0, $this->minimumFreeBytes - $freeBytes);
        $healthy = $shortfall === 0;

        return new HealthCheckResultData(
            id: $this->id(),
            category: $this->category(),
            status: $healthy ? HealthStatus::Healthy : HealthStatus::Failed,
            severity: $healthy ? HealthSeverity::Info : HealthSeverity::Critical,
            summary: $healthy
                ? sprintf('%s free; minimum %s.', self::humanBytes($freeBytes), self::humanBytes($this->minimumFreeBytes))
                : sprintf('%s free; minimum %s; shortfall %s.', self::humanBytes($freeBytes), self::humanBytes($this->minimumFreeBytes), self::humanBytes($shortfall)),
            remediation: $healthy ? null : 'Free disk capacity or move storage before continuing write-heavy operations.',
            metrics: ['freeBytes' => $freeBytes, 'minimumFreeBytes' => $this->minimumFreeBytes, 'shortfallBytes' => $shortfall],
        );
    }
}
