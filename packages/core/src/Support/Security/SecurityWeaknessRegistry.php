<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Contracts\Security\SecurityWeaknessCheck;
use Capell\Core\Contracts\Security\SecurityWeaknessRemediation;
use InvalidArgumentException;
use LogicException;

/**
 * The known weaknesses this installation can detect, and the fixes it can
 * apply for them.
 *
 * Boot-only, like the other typed registries: the container freezes it on
 * `booted`, so request-time registration cannot leak across Octane requests.
 * Package installation re-opens it through {@see self::duringPackageInstallation()}.
 *
 * @experimental
 */
final class SecurityWeaknessRegistry
{
    /** @var array<string, SecurityWeaknessCheck> keyed by weakness id */
    private array $checks = [];

    /** @var array<string, SecurityWeaknessRemediation> keyed by weakness id */
    private array $remediations = [];

    /** @var array<string, string> */
    private array $checkOwners = [];

    /** @var array<string, string> */
    private array $remediationOwners = [];

    private bool $frozen = false;

    private int $installationDepth = 0;

    public static function isValid(string $weaknessId): bool
    {
        return strlen($weaknessId) <= 120 && preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $weaknessId) === 1;
    }

    public function registerCheck(SecurityWeaknessCheck $check, ?string $owner = null): self
    {
        $this->assertOpen();
        $id = $this->assertWeaknessId($check->weaknessId());

        $owner ??= $check::class;
        if (isset($this->checkOwners[$id]) && $this->checkOwners[$id] !== $owner) {
            throw new InvalidArgumentException(__('capell-core::security.registry.owner_conflict', ['id' => $id, 'owner' => $this->checkOwners[$id], 'replacement' => $owner]));
        }

        $this->checkOwners[$id] = $owner;
        $this->checks[$id] = $check;

        return $this;
    }

    public function registerRemediation(SecurityWeaknessRemediation $remediation, ?string $owner = null): self
    {
        $this->assertOpen();
        $id = $this->assertWeaknessId($remediation->weaknessId());

        $owner ??= $remediation::class;
        if (isset($this->remediationOwners[$id]) && $this->remediationOwners[$id] !== $owner) {
            throw new InvalidArgumentException(__('capell-core::security.registry.owner_conflict', ['id' => $id, 'owner' => $this->remediationOwners[$id], 'replacement' => $owner]));
        }

        $this->remediationOwners[$id] = $owner;
        $this->remediations[$id] = $remediation;

        return $this;
    }

    /** @return array<string, SecurityWeaknessCheck> */
    public function checks(): array
    {
        return $this->checks;
    }

    /** @return array<string, SecurityWeaknessRemediation> */
    public function remediations(): array
    {
        return $this->remediations;
    }

    public function check(string $weaknessId): ?SecurityWeaknessCheck
    {
        return $this->checks[$weaknessId] ?? null;
    }

    public function remediation(string $weaknessId): ?SecurityWeaknessRemediation
    {
        return $this->remediations[$weaknessId] ?? null;
    }

    public function knows(string $weaknessId): bool
    {
        return isset($this->checks[$weaknessId]) || isset($this->remediations[$weaknessId]);
    }

    public function freeze(): void
    {
        $this->frozen = true;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function duringPackageInstallation(callable $callback): mixed
    {
        $this->installationDepth++;

        try {
            return $callback();
        } finally {
            $this->installationDepth--;
        }
    }

    private function assertWeaknessId(string $weaknessId): string
    {
        if (! self::isValid($weaknessId)) {
            throw new InvalidArgumentException(sprintf('Security weakness id "%s" must be lower-case dotted segments, e.g. "app.debug_enabled".', $weaknessId));
        }

        return $weaknessId;
    }

    private function assertOpen(): void
    {
        throw_if($this->frozen && $this->installationDepth === 0, LogicException::class, 'Security weakness checks and remediations must be registered during boot or package installation.');
    }
}
