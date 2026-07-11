<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Spatie\LaravelData\Data;

final class InstallStepData extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $requiresResolvedUser = false,
    ) {}

    /**
     * @return array{key: string, label: string}
     */
    public function toPlanArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
        ];
    }
}
