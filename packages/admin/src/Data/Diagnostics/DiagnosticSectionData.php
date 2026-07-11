<?php

declare(strict_types=1);

namespace Capell\Admin\Data\Diagnostics;

use Spatie\LaravelData\Data;

final class DiagnosticSectionData extends Data
{
    /**
     * @param  list<DiagnosticCheckData>  $checks
     */
    public function __construct(
        public readonly string $label,
        public readonly array $checks,
    ) {}
}
