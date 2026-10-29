<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Override;
use Spatie\LaravelData\Data;

final class InstallRunResultData extends Data
{
    /**
     * @param  list<string>  $selectedPackages
     * @param  list<string>  $completedSteps
     * @param  list<array{message: string, count: int}>  $doctorWarnings
     */
    public function __construct(
        public readonly array $selectedPackages,
        public readonly array $completedSteps,
        public readonly string $doctorStatus,
        public readonly array $doctorWarnings = [],
    ) {}

    /**
     * @return array{selectedPackages: list<string>, completedSteps: list<string>, doctorStatus: string, doctorWarnings: list<array{message: string, count: int}>}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'selectedPackages' => $this->selectedPackages,
            'completedSteps' => $this->completedSteps,
            'doctorStatus' => $this->doctorStatus,
            'doctorWarnings' => $this->doctorWarnings,
        ];
    }
}
