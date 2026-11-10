<?php

declare(strict_types=1);

namespace Workbench\App\Support\Data;

final readonly class CaseStudyPublicData
{
    public function __construct(public ?string $clientName) {}
}
