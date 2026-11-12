<?php

declare(strict_types=1);

namespace Capell\Core\Support\Url;

final readonly class RedirectTargetResult
{
    private function __construct(
        public ?string $target,
        public ?RedirectTargetRejection $rejection,
    ) {}

    public static function allow(string $target): self
    {
        return new self($target, null);
    }

    public static function reject(RedirectTargetRejection $rejection): self
    {
        return new self(null, $rejection);
    }

    public function allowed(): bool
    {
        return ! $this->rejection instanceof RedirectTargetRejection;
    }
}
