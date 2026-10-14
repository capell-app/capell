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
        $state['scaffolded_panel_providers'] = array_values(array_unique([
            ...self::scaffoldedPanelProviders(),
            ...array_map(fn (string $path): string => str($path)->after(base_path() . DIRECTORY_SEPARATOR)->toString(), $paths),
        ]));
        throw_unless($store->forever('capell.install.state', $state), RuntimeException::class, 'Unable to persist panel scaffold provenance.');
    }

    /** @return list<string> */
    public static function scaffoldedPanelProviders(): array
    {
        $state = self::progressStore()->get('capell.install.state', []);
        $paths = is_array($state) ? ($state['scaffolded_panel_providers'] ?? []) : [];

        return is_array($paths) ? array_values(array_filter($paths, is_string(...))) : [];
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
