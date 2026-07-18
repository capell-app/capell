<?php

declare(strict_types=1);

namespace Capell\Core\Support\Diagnostics\Checks;

use Capell\Core\Data\Diagnostics\DoctorCheckResultData;
use Capell\Core\Enums\Diagnostics\DoctorCheckSeverity;
use Capell\Core\Support\Json\JsonCodec;
use Throwable;

final class ViteInputsCheck extends AbstractDoctorCheck
{
    protected function id(): string
    {
        return 'core.assets.vite-inputs';
    }

    protected function severity(): DoctorCheckSeverity
    {
        return DoctorCheckSeverity::Warning;
    }

    protected function run(bool $installSummary): DoctorCheckResultData
    {
        $manifestPath = base_path('bootstrap/cache/capell-vite-inputs.json');

        if (! is_file($manifestPath)) {
            return new DoctorCheckResultData('Capell Vite inputs are integrated', true, 'No generated Capell Vite inputs require integration.');
        }

        try {
            $manifest = JsonCodec::decodeArray((string) file_get_contents($manifestPath));
        } catch (Throwable) {
            $manifest = null;
        }

        if (! is_array($manifest) || ! is_array($manifest['inputs'] ?? null)) {
            return new DoctorCheckResultData('Capell Vite inputs are integrated', false, 'The generated Capell Vite input manifest is invalid.', 'Run php artisan capell:frontend-after-install --apply to regenerate it.');
        }

        if ($manifest['inputs'] === []) {
            return new DoctorCheckResultData('Capell Vite inputs are integrated', true, 'The generated Capell Vite input manifest has no application entries.');
        }

        $viteConfigPath = collect(['vite.config.js', 'vite.config.mjs', 'vite.config.ts'])
            ->map(static fn (string $file): string => base_path($file))
            ->first(static fn (string $file): bool => is_file($file));

        if (! is_string($viteConfigPath) || ! str_contains((string) file_get_contents($viteConfigPath), 'capellViteInputs')) {
            return new DoctorCheckResultData(
                'Capell Vite inputs are integrated',
                false,
                'Generated Capell Vite entries are not included in the application Vite configuration.',
                "Import capellViteInputs from '@capell/frontend/capell-vite-inputs' and spread ...capellViteInputs() into the Laravel Vite input array.",
            );
        }

        return new DoctorCheckResultData('Capell Vite inputs are integrated', true, sprintf('%d generated Capell Vite input(s) are integrated.', count($manifest['inputs'])));
    }
}
