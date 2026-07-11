<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Pages;

use Spatie\LaravelData\Data;

final class PublishVisibilityActionResultData extends Data
{
    public function __construct(
        public bool $changed,
        public bool $skipped,
        public ?string $reason = null,
    ) {}

    public static function changed(): self
    {
        return new self(changed: true, skipped: false);
    }

    public static function skipped(string $reason): self
    {
        return new self(changed: false, skipped: true, reason: $reason);
    }
}
