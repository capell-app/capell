<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Marketplace;

use Capell\Core\Data\Marketplace\ExtensionHealthReportData;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Foundation\Application;
use Lorisleiva\Actions\Concerns\AsAction;
use Throwable;

final class BuildExtensionHealthReportAction
{
    use AsAction;

    /**
     * @return array<string, mixed>
     */
    public function handle(string $source, ?string $instanceId = null, ?string $webhookUrl = null): array
    {
        return new ExtensionHealthReportData(
            installId: $instanceId,
            appUrl: $this->configuredString('app.url'),
            capellVersion: $this->capellVersion(),
            laravelVersion: Application::VERSION,
            phpVersion: PHP_VERSION,
            environment: $this->configuredString('app.env'),
            generatedAt: now()->toIso8601String(),
            extensions: $this->extensions(),
            metadata: array_filter([
                'source' => $source,
                'webhook_url' => $webhookUrl,
            ], fn (mixed $value): bool => $value !== null),
        )->toArray();
    }

    private function configuredString(string $key): ?string
    {
        $value = config($key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private function capellVersion(): ?string
    {
        $version = config('capell.version');

        return is_scalar($version) && (string) $version !== '' ? (string) $version : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extensions(): array
    {
        if (! app()->bound('db') || ! $this->extensionsTableExists()) {
            return [];
        }

        return CapellExtension::query()
            ->orderBy('composer_name')
            ->get()
            ->map(fn (CapellExtension $extension): array => [
                'composer_name' => $extension->composer_name,
                'name' => $extension->name,
                'version' => $extension->version,
                'source' => $extension->source,
                'status' => $extension->status->value,
                'is_paid_marketplace_extension' => $extension->is_paid_marketplace_extension,
                'marketplace_runtime_status' => $extension->marketplace_runtime_status,
                'marketplace_runtime_allowed' => $extension->marketplace_runtime_allowed,
                'marketplace_runtime_reason' => $extension->marketplace_runtime_reason,
            ])
            ->values()
            ->all();
    }

    private function extensionsTableExists(): bool
    {
        try {
            return resolve(RuntimeSchemaState::class)->hasTable('capell_extensions');
        } catch (Throwable) {
            return false;
        }
    }
}
