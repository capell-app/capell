<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Diagnostics;

enum HostMonitorStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Critical = 'critical';

    public static function fromConfig(mixed $value, self $default): self
    {
        return is_string($value) ? (self::tryFrom(strtolower($value)) ?? $default) : $default;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Warning => 1,
            self::Critical => 2,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Ok => __('capell-core::health.host_monitor.status.ok'),
            self::Warning => __('capell-core::health.host_monitor.status.warning'),
            self::Critical => __('capell-core::health.host_monitor.status.critical'),
        };
    }
}
