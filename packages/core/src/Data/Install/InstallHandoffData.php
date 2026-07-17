<?php

declare(strict_types=1);

namespace Capell\Core\Data\Install;

use Override;
use Spatie\LaravelData\Data;

final class InstallHandoffData extends Data
{
    /**
     * @param  list<string>  $selectedPackages
     * @param  array{migrations: string, setup: string, doctor: string}  $outcomes
     * @param  array{admin: string|null, public: string}  $urls
     * @param  array{status: string}  $firstPage
     * @param  list<string>  $warnings
     * @param  array{label: string, url: string}  $nextAction
     * @param  array{summary: string, accountConnection: string, telemetrySubmission: string}  $publicImpact
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $status,
        public readonly array $selectedPackages,
        public readonly array $outcomes,
        public readonly array $urls,
        public readonly array $firstPage,
        public readonly array $warnings,
        public readonly array $nextAction,
        public readonly array $publicImpact,
    ) {}

    /**
     * @return array{
     *     schemaVersion: int,
     *     status: string,
     *     selectedPackages: list<string>,
     *     outcomes: array{migrations: string, setup: string, doctor: string},
     *     urls: array{admin: string|null, public: string},
     *     firstPage: array{status: string},
     *     warnings: list<string>,
     *     nextAction: array{label: string, url: string},
     *     publicImpact: array{summary: string, accountConnection: string, telemetrySubmission: string}
     * }
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'schemaVersion' => $this->schemaVersion,
            'status' => $this->status,
            'selectedPackages' => $this->selectedPackages,
            'outcomes' => $this->outcomes,
            'urls' => $this->urls,
            'firstPage' => $this->firstPage,
            'warnings' => $this->warnings,
            'nextAction' => $this->nextAction,
            'publicImpact' => $this->publicImpact,
        ];
    }
}
