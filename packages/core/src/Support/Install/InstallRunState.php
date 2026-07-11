<?php

declare(strict_types=1);

namespace Capell\Core\Support\Install;

use Capell\Core\Contracts\ProgressReporter;
use Capell\Core\Data\InstallInputData;
use Capell\Core\Data\PackageData;
use Capell\Core\Facades\CapellCore;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

final class InstallRunState
{
    /** @var Collection<string, PackageData>|null */
    private ?Collection $selectedPackages = null;

    public function __construct(
        public readonly InstallInputData $inputData,
        public readonly ProgressReporter $reporter,
        private ?int $resolvedUserId = null,
    ) {}

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
        )->reject(fn (PackageData $package): bool => $package->isCore());

        return $this->selectedPackages;
    }

    public function refreshSelectedPackages(): self
    {
        $this->selectedPackages = null;

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
}
