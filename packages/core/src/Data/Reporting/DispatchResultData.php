<?php

declare(strict_types=1);

namespace Capell\Core\Data\Reporting;

use Capell\Core\Enums\Reporting\DispatchStatus;

final readonly class DispatchResultData
{
    public function __construct(
        public DispatchStatus $status,
        public ?string $transport = null,
        public ?string $reason = null,
    ) {}
}
