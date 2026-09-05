<?php

declare(strict_types=1);

namespace Capell\Core\Data\Properties;

use Spatie\LaravelData\Data;

final class AgentSchemaReportData extends Data
{
    /** @param list<array{check: string, subject: string, problem: string}> $failures */
    public function __construct(public array $failures, public int $pagesChecked) {}

    public function passed(): bool
    {
        return $this->failures === [];
    }
}
