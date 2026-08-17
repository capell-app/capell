<?php

declare(strict_types=1);

namespace Capell\Core\Support\Bootstrap;

/**
 * Resolves the install selection before Laravel creates its request instance.
 *
 * Cloud provisioning exports these values before PHP starts. Reading the
 * process directly keeps provider selection available while Core is being
 * registered, including with cached configuration and before request capture.
 */
final readonly class CloudInstallContext
{
    /** @param array<string, true> $selectedPackages */
    private function __construct(
        private bool $cloudInstall,
        private array $selectedPackages,
    ) {}

    public static function fromProcess(): self
    {
        $mode = self::processValue('CAPELL_INSTALL_MODE');

        return new self(
            cloudInstall: $mode === 'cloud',
            selectedPackages: self::selectedPackages(self::processValue('CAPELL_INSTALL_PACKAGES')),
        );
    }

    /** @param list<string> $packageNames */
    public static function forCloudPackages(array $packageNames): self
    {
        /** @var array<string, true> $selectedPackages */
        $selectedPackages = [];

        foreach ($packageNames as $packageName) {
            $trimmedName = trim($packageName);

            if ($trimmedName !== '') {
                $selectedPackages[$trimmedName] = true;
            }
        }

        return new self(cloudInstall: true, selectedPackages: $selectedPackages);
    }

    public function isCloudInstall(): bool
    {
        return $this->cloudInstall;
    }

    public function selects(string $packageName): bool
    {
        return isset($this->selectedPackages[$packageName]);
    }

    private static function processValue(string $key): ?string
    {
        $value = getenv($key);

        if (! is_string($value)) {
            return null;
        }

        $trimmedValue = trim($value);

        return $trimmedValue !== '' ? $trimmedValue : null;
    }

    /** @return array<string, true> */
    private static function selectedPackages(?string $packages): array
    {
        if ($packages === null) {
            return [];
        }

        /** @var array<string, true> $selectedPackages */
        $selectedPackages = [];

        foreach (explode(',', $packages) as $packageName) {
            $trimmedName = trim($packageName);

            if ($trimmedName !== '') {
                $selectedPackages[$trimmedName] = true;
            }
        }

        return $selectedPackages;
    }
}
