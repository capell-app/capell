<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Override;
use Spatie\LaravelData\Data;

final class DoctorCheckResultData extends Data
{
    public function __construct(
        public string $label,
        public bool $passed,
        public string $message,
        public ?string $remediation = null,
    ) {}

    /**
     * @return array{label: string, passed: bool, message: string, remediation: string|null}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'passed' => $this->passed,
            'message' => $this->message,
            'remediation' => $this->remediation,
        ];
    }
}
