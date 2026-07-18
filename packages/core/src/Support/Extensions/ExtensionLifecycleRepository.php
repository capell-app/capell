<?php

declare(strict_types=1);

namespace Capell\Core\Support\Extensions;

use Capell\Core\Actions\ResolveExtensionRuntimeGateAction;
use Capell\Core\Data\ExtensionRuntimeGateData;
use Capell\Core\Data\PackageData;
use Capell\Core\Enums\ExtensionStatusEnum;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Database\RuntimeSchemaState;
use Illuminate\Support\Collection;
use Throwable;

final class ExtensionLifecycleRepository
{
    /** @var array<string, ExtensionStatusEnum|null> */
    private array $statusCache = [];

    /** @var array<string, ExtensionRuntimeGateData|null> */
    private array $runtimeGateCache = [];

    /** @var array<string, CapellExtension|null> */
    private array $recordCache = [];

    private bool $recordsPreloaded = false;

    public function __construct(private readonly RuntimeSchemaState $schemaState) {}

    /** @param Collection<string, PackageData> $packages */
    public function status(string $name, Collection $packages): ?ExtensionStatusEnum
    {
        if (array_key_exists($name, $this->statusCache)) {
            return $this->statusCache[$name];
        }

        $extension = $this->record($name, $packages);

        return $extension instanceof CapellExtension
            ? $this->statusCache[$name] = $extension->status
            : null;
    }

    /** @param Collection<string, PackageData> $packages */
    public function runtimeGateAllows(string $name, Collection $packages): ?bool
    {
        if (array_key_exists($name, $this->runtimeGateCache)) {
            return $this->runtimeGateCache[$name]?->allowed;
        }

        $extension = $this->record($name, $packages);

        if (! $extension instanceof CapellExtension) {
            $this->runtimeGateCache[$name] = null;

            return null;
        }

        $this->runtimeGateCache[$name] = ResolveExtensionRuntimeGateAction::run($extension);

        return $this->runtimeGateCache[$name]?->allowed;
    }

    public function recordInstalled(string $name, ?PackageData $package): void
    {
        if (! $this->tableExists(refresh: true)) {
            return;
        }

        $existing = CapellExtension::query()->where('composer_name', $name)->first();

        CapellExtension::query()->updateOrCreate(
            ['composer_name' => $name],
            [
                'name' => $package?->getShortName(),
                'description' => $package?->getDescription(),
                'version' => $package?->version,
                'source' => $package?->path !== null ? 'local' : 'composer',
                'status' => ExtensionStatusEnum::Enabled,
                'enabled_at' => $existing->enabled_at ?? now(),
                'disabled_at' => null,
                'failed_at' => null,
                'installed_at' => $existing->installed_at ?? now(),
                'metadata' => $this->packageMetadata($package),
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    public function recordLifecycle(
        string $name,
        ExtensionStatusEnum $status,
        ?PackageData $package,
        array $metadata = [],
    ): void {
        if (! $this->tableExists(refresh: true)) {
            return;
        }

        $existing = CapellExtension::query()->where('composer_name', $name)->first();
        $existingMetadata = is_array($existing?->metadata) ? $existing->metadata : [];

        CapellExtension::query()->updateOrCreate(
            ['composer_name' => $name],
            [
                'name' => $package?->getShortName(),
                'description' => $package?->getDescription(),
                'version' => $package?->version,
                'source' => $package?->path !== null ? 'local' : 'composer',
                'status' => $status,
                'enabled_at' => null,
                'disabled_at' => $status === ExtensionStatusEnum::Disabled ? now() : null,
                'failed_at' => $status === ExtensionStatusEnum::Failed ? now() : null,
                'installed_at' => $existing?->installed_at,
                'metadata' => array_merge($existingMetadata, $this->packageMetadata($package), $metadata),
            ],
        );
    }

    public function delete(string $name): void
    {
        if (! $this->tableExists(refresh: true)) {
            return;
        }

        CapellExtension::query()->where('composer_name', $name)->delete();
    }

    public function clear(): void
    {
        $this->statusCache = [];
        $this->runtimeGateCache = [];
        $this->recordCache = [];
        $this->recordsPreloaded = false;
        $this->schemaState->forgetTable('capell_extensions');
    }

    /** @param Collection<string, PackageData> $packages */
    private function record(string $name, Collection $packages): ?CapellExtension
    {
        if (array_key_exists($name, $this->recordCache)) {
            return $this->recordCache[$name];
        }

        $this->preloadRecords($packages);

        if (array_key_exists($name, $this->recordCache)) {
            return $this->recordCache[$name];
        }

        if (! $this->tableExists()) {
            return null;
        }

        try {
            return $this->recordCache[$name] = CapellExtension::query()
                ->where('composer_name', $name)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param Collection<string, PackageData> $packages */
    private function preloadRecords(Collection $packages): void
    {
        if ($this->recordsPreloaded || ! $this->tableExists()) {
            return;
        }

        $packageNames = $packages
            ->reject(fn (PackageData $package): bool => $package->isCore())
            ->map(fn (PackageData $package): string => $package->name)
            ->values()
            ->all();

        if ($packageNames === []) {
            return;
        }

        try {
            $extensions = CapellExtension::query()
                ->whereIn('composer_name', $packageNames)
                ->get()
                ->keyBy('composer_name');

            foreach ($packageNames as $packageName) {
                $extension = $extensions->get($packageName);
                $this->recordCache[$packageName] = $extension instanceof CapellExtension ? $extension : null;
            }

            $this->recordsPreloaded = true;
        } catch (Throwable) {
            $this->recordCache = [];
        }
    }

    private function tableExists(bool $refresh = false): bool
    {
        return app()->bound('db')
            && $this->schemaState->hasTable('capell_extensions', refresh: $refresh);
    }

    /** @return array<string, string|null> */
    private function packageMetadata(?PackageData $package): array
    {
        return [
            'product_group' => $package?->getProductGroup(),
            'tier' => $package?->getTier(),
            'kind' => $package?->getKind(),
        ];
    }
}
