<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Packages\TrustedCorePackages;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class InstallRunState
{
    /** @var Collection<string, PackageData>|null */
    private ?Collection $selectedPackages = null;

    public function __construct(
        public readonly InstallInputData $inputData,
        public readonly ProgressReporter $reporter,
        private ?int $resolvedUserId = null,
        private bool $packageMetadataRefreshed = false,
    ) {}

    /** @param list<string> $paths */
    public static function recordScaffoldedPanelProviders(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                $state = self::progressState();
                self::savePanelProvider($path, true, is_string($state['panel_scaffold_run'] ?? null) ? $state['panel_scaffold_run'] : null);

                return;
            }
        }
    }

    /** @return list<string> */
    public static function scaffoldedPanelProviders(): array
    {
        $record = self::panelProviderRecord();
        $path = self::authorisedPanelProvider();

        return $record !== null && $record['pending'] && $path !== null ? [self::panelProviderKey($path)] : [];
    }

    public static function authorisedPanelProvider(): ?string
    {
        $record = self::panelProviderRecord();

        if ($record === null) {
            return null;
        }

        $path = str_starts_with($record['path'], DIRECTORY_SEPARATOR) ? $record['path'] : base_path($record['path']);
        $hash = is_file($path) ? hash_file('sha256', $path) : false;

        return $hash !== false && hash_equals($record['hash'], $hash) ? (realpath($path) ?: $path) : null;
    }

    public static function hasPanelProviderRecord(): bool
    {
        $state = self::progressState();

        return array_key_exists('panel_provider', $state) || ($state['scaffolded_panel_providers'] ?? []) !== [];
    }

    public static function recordPanelProviderSave(string $path, string $original): void
    {
        $record = self::panelProviderRecord();
        $wasOwned = $record !== null && $record['path'] === self::panelProviderKey($path) && hash_equals($record['hash'], hash('sha256', $original));
        self::savePanelProvider($path, $wasOwned && $record['pending'], $wasOwned ? $record['run'] : null);
    }

    public static function retireScaffoldedPanelProviders(?string $runId = null): void
    {
        $record = self::panelProviderRecord();

        if ($record === null || ($runId !== null && $record['run'] !== null && $record['run'] !== $runId)) {
            return;
        }

        // Completing a step retires pending work, not ownership of the panel it serves.
        $state = self::progressState();
        $state['panel_provider'] = [...$record, 'pending' => false];
        unset($state['scaffolded_panel_providers']);
        self::saveProgressState($state);
    }

    public static function usePanelScaffoldRun(string $runId): void
    {
        $state = self::progressState();
        $state['panel_scaffold_run'] = $runId;
        self::saveProgressState($state);
    }

    public static function panelProviderKey(string $path): string
    {
        $absolutePath = realpath($path) ?: $path;
        $basePath = realpath(base_path()) ?: base_path();

        return str($absolutePath)->after($basePath . DIRECTORY_SEPARATOR)->toString();
    }

    /**
     * @return Collection<string, PackageData>
     */
    public function selectedPackages(): Collection
    {
        if ($this->selectedPackages instanceof Collection) {
            return $this->selectedPackages;
        }

        $this->selectedPackages = resolve(PackageWorkflowPlanner::class)->expandAndOrder(
            CapellCore::getPackages(),
            array_values(array_unique([
                ...$this->inputData->packages,
                ...$this->inputData->extraPackages,
            ])),
            $this->inputData->freshInstall,
        )->reject(fn (PackageData $package): bool => TrustedCorePackages::isCoreRuntimePackage($package->name));

        return $this->selectedPackages;
    }

    public function refreshSelectedPackages(): self
    {
        $this->selectedPackages = null;

        return $this;
    }

    public function packageMetadataIsRefreshed(): bool
    {
        return $this->packageMetadataRefreshed;
    }

    public function markPackageMetadataRefreshed(): self
    {
        $this->packageMetadataRefreshed = true;

        return $this;
    }

    public function setResolvedUser(Authenticatable $user): self
    {
        $this->resolvedUserId = (int) $user->getAuthIdentifier();

        return $this;
    }

    public function resolvedUserId(): ?int
    {
        return $this->resolvedUserId;
    }

    public function resolvedUser(): Authenticatable
    {
        throw_if($this->resolvedUserId === null, RuntimeException::class, 'User must be resolved before this step. Run resolve-user first.');

        /** @var class-string<Model&Authenticatable> $userModel */
        $userModel = config('auth.providers.users.model');

        $user = $userModel::query()->find($this->resolvedUserId);

        if (! $user instanceof Authenticatable) {
            throw new RuntimeException(sprintf('Resolved user %d not found.', $this->resolvedUserId));
        }

        return $user;
    }

    private static function savePanelProvider(string $path, bool $pending, ?string $run): void
    {
        $hash = hash_file('sha256', $path);
        throw_unless(is_string($hash), RuntimeException::class, 'Unable to read panel provider identity.');
        $state = self::progressState();
        $state['panel_provider'] = ['path' => self::panelProviderKey($path), 'hash' => $hash, 'pending' => $pending, 'run' => $run];
        unset($state['scaffolded_panel_providers']);
        self::saveProgressState($state);
    }

    /** @return array{path: string, hash: string, pending: bool, run: ?string}|null */
    private static function panelProviderRecord(): ?array
    {
        $state = self::progressState();
        $record = $state['panel_provider'] ?? null;

        if (is_array($record) && is_string($record['path'] ?? null) && is_string($record['hash'] ?? null)) {
            return [
                'path' => $record['path'],
                'hash' => $record['hash'],
                'pending' => ($record['pending'] ?? false) === true,
                'run' => is_string($record['run'] ?? null) ? $record['run'] : null,
            ];
        }

        $legacy = $state['scaffolded_panel_providers'] ?? [];

        foreach (is_array($legacy) ? $legacy : [] as $path => $hash) {
            // Path-only records cannot establish ownership of the current file.
            if (is_string($path) && is_string($hash)) {
                return ['path' => $path, 'hash' => $hash, 'pending' => true, 'run' => null];
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function progressState(): array
    {
        $state = self::progressStore()->get('capell.install.state', []);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private static function saveProgressState(array $state): void
    {
        throw_unless(self::progressStore()->forever('capell.install.state', $state), RuntimeException::class, 'Unable to persist panel provider ownership.');
    }

    private static function progressStore(): Repository
    {
        // File-backed install progress survives fresh invocations before database setup.
        return Cache::build([
            'driver' => 'file',
            'path' => base_path('storage/framework/cache/data'),
        ]);
    }
}
