<?php

declare(strict_types=1);

namespace Capell\Core\EventSourcing\Rollback;

use Spatie\LaravelData\Data;

/**
 * A single validation finding produced while previewing a rollback (e.g. a
 * slug that would collide, or a blueprint that no longer exists). Blocking
 * issues prevent apply; warnings are informational.
 */
final class RollbackIssueData extends Data
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly RollbackSeverity $severity,
        public readonly ?string $path = null,
    ) {}

    public static function blocking(string $code, string $message, ?string $path = null): self
    {
        return new self($code, $message, RollbackSeverity::Blocking, $path);
    }

    public static function warning(string $code, string $message, ?string $path = null): self
    {
        return new self($code, $message, RollbackSeverity::Warning, $path);
    }

    public function isBlocking(): bool
    {
        return $this->severity === RollbackSeverity::Blocking;
    }
}
