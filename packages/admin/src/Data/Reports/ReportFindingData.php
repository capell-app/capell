<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Reports;

use Capell\Admin\Enums\Reports\ReportFindingSeverity;
use Spatie\LaravelData\Data;

final class ReportFindingData extends Data
{
    public function __construct(
        public readonly ReportFindingSeverity $severity,
        public readonly string $title,
        public readonly string $description,
        public readonly ?string $recordLabel = null,
        public readonly ?string $actionLabel = null,
        public readonly ?string $url = null,
        public readonly ?string $id = null,
        public readonly ?string $remediation = null,
        /** @var array<string, mixed> */
        public readonly array $evidence = [],
    ) {}
}
