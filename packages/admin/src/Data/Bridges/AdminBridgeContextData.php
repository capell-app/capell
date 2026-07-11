<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Bridges;

use Spatie\LaravelData\Data;

final class AdminBridgeContextData extends Data
{
    public function __construct(
        public string $packageName,
    ) {}

    public static function forPackage(string $packageName): self
    {
        return new self($packageName);
    }
}
