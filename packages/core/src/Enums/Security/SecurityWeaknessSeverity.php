<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Security;

/**
 * How urgently a detected weakness needs attention.
 *
 * Ordered so that callers can compare two findings without caring about the
 * string values the database stores.
 */
enum SecurityWeaknessSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
