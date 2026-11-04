<?php

declare(strict_types=1);

namespace Capell\Admin\Support\Transfers;

/** The panel a Filament export or import was started in, and the host (with any port) that served the request. */
final readonly class TransferOrigin
{
    public function __construct(
        public string $panel,
        public string $host,
    ) {}
}
