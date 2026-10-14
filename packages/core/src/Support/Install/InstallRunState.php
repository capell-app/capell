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
        if ($paths === []) {
            return;
        }

        $store = self::progressStore();
        $state = $store->get('capell.install.state', []);
        $state = is_array($state) ? $state : [];

        $identities = [];

        foreach (array_unique([...self::scaffoldedPanelProviders(), ...$paths]) as $path) {
            $absolutePath = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
            $hash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;

            if ($hash !== false) {
                $identities[str($absolutePath)->after(base_path() . DIRECTORY_SEPARATOR)->toString()] = $hash;
            }
        }

        $state['scaffolded_panel_providers'] = $identities;
        throw_unless($store->forever('capell.install.state', $state), RuntimeException::class, 'Unable to persist panel scaffold provenance.');
    }

    /** @return list<string> */
    public static function scaffoldedPanelProviders(): array
    {
        $state = self::progressStore()->get('capell.install.state', []);
        $identities = is_array($state) ? ($state['scaffolded_panel_providers'] ?? []) : [];
        $paths = [];

        if (! is_array($identities)) {
            return [];
        }

        foreach ($identities as $path => $hash) {
            // Legacy path-only records cannot establish ownership of the current file.
            if (! is_string($path)) {
                continue;
            }

            if (! is_string($hash)) {
                continue;
            }

            $absolutePath = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
            $currentHash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : false;

            if ($currentHash !== false && hash_equals($hash, $currentHash)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public static function retireScaffoldedPanelProviders(): void
    {
        $store = self::progressStore();
        $state = $store->get('capell.install.state', []);

        if (! is_array($state) || ! array_key_exists('scaffolded_panel_providers', $state)) {
            return;
        }

        unset($state['scaffolded_panel_providers']);
        throw_unless($store->forever('capell.install.state', $state), RuntimeException::class, 'Unable to retire panel scaffold provenance.');
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

    private static function progressStore(): Repository
    {
        // File-backed install progress survives fresh invocations before database setup.
        return Cache::build([
            'driver' => 'file',
            'path' => base_path('storage/framework/cache/data'),
        ]);
    }
}
