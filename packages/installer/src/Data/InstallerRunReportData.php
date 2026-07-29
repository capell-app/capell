<?php

declare(strict_types=1);

namespace Capell\Installer\Data;

use Spatie\LaravelData\Data;

final class InstallerRunReportData extends Data
{
    /**
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $preflight
     * @param  array<int, array<string, mixed>>  $plan
     * @param  array<string, mixed>  $diagnostics
     * @param  array<string, mixed>  $selected
     * @param  array<int, mixed>  $lines
     * @param  list<string>  $remediations
     */
    public function __construct(
        public readonly string $installId,
        public readonly string $status,
        public readonly array $environment,
        public readonly array $preflight,
        public readonly array $plan,
        public readonly array $diagnostics,
        public readonly array $selected,
        public readonly array $lines,
        public readonly array $remediations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'installId' => $this->installId,
            'status' => $this->status,
            'environment' => $this->environment,
            'preflight' => $this->preflight,
            'plan' => $this->plan,
            'diagnostics' => $this->diagnostics,
            'selected' => $this->selected,
            'lines' => $this->lines,
            'remediations' => $this->remediations,
        ];
    }
}
