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
        public readonly bool $passwordOmitted = false,
    ) {}

    /** The resume instruction, with a reminder when the first run's password could not be replayed. */
    public function remediation(): string
    {
        $remediation = __('capell-core::health.install_lifecycle.stopped_remediation', ['command' => $this->resumeCommand]);

        return $this->passwordOmitted
            ? $remediation . ' ' . __('capell-core::health.install_lifecycle.stopped_password_note')
            : $remediation;
    }

    /**
     * @return array{key: string, label: string, resumeCommand: string, passwordOmitted: bool}
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'resumeCommand' => $this->resumeCommand,
            'passwordOmitted' => $this->passwordOmitted,
        ];
    }
}
