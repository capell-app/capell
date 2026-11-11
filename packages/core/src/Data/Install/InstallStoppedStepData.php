<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Override;
use Spatie\LaravelData\Data;

final class InstallStoppedStepData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $resumeCommand,
    ) {}

    /**
     * @return array{key: string, label: string, resumeCommand: string}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'resumeCommand' => $this->resumeCommand,
        ];
    }
}
