<?php

declare(strict_types=1);

namespace Capell\Admin\Data\AdminPanelIntegration;

use Capell\Admin\Enums\AdminPanelChangeStatus;
use Capell\Admin\Enums\AdminPanelFailureCategory;
use Spatie\LaravelData\Data;

final class AdminPanelChangeResultData extends Data
{
    public function __construct(
        public readonly string $change,
        public readonly AdminPanelChangeStatus $status,
        public readonly string $message,
        public readonly ?AdminPanelFailureCategory $failureCategory = null,
        public readonly ?string $docUrl = null,
        public readonly ?int $line = null,
    ) {}
}
