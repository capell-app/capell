<?php

declare(strict_types=1);

namespace Capell\Marketplace\Data;

/**
 * The two choices an operator makes on the uninstall modal, carried from the
 * request that queued the operation to the worker that performs it.
 *
 * Written by hand rather than derived, and with the persisted shape stated once
 * in both directions, because this payload crosses a queue: the request that
 * produced it is long gone by the time the job reads it back, so a key that
 * serialises under one name and is read under another does not fail — it
 * silently answers false, and the operator's "also delete the package" quietly
 * becomes "keep it". MarketplaceUninstallOptionsDataTest pins the round trip.
 */
final readonly class MarketplaceUninstallOptionsData
{
    public function __construct(
        public bool $deletePackage = false,
        public bool $deleteData = false,
        /** @var list<string> */
        public array $packageNames = [],
        public bool $runLifecycle = true,
    ) {}

    /**
     * @param  array<array-key, mixed>|null  $payload
     */
    public static function fromPayload(?array $payload): self
    {
        if ($payload === null) {
            return new self;
        }

        return new self(
            deletePackage: ($payload['delete_package'] ?? false) === true,
            deleteData: ($payload['delete_data'] ?? false) === true,
            packageNames: self::packageNames($payload['package_names'] ?? []),
            runLifecycle: ($payload['run_lifecycle'] ?? true) === true,
        );
    }

    /**
     * @return array{delete_package: bool, delete_data: bool, package_names?: list<string>, run_lifecycle?: false}
     */
    public function toArray(): array
    {
        $payload = [
            'delete_package' => $this->deletePackage,
            'delete_data' => $this->deleteData,
        ];

        if ($this->packageNames !== []) {
            $payload['package_names'] = $this->packageNames;
        }

        if (! $this->runLifecycle) {
            $payload['run_lifecycle'] = false;
        }

        return $payload;
    }

    /** @return list<string> */
    private static function packageNames(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $value,
            static fn (mixed $packageName): bool => is_string($packageName) && trim($packageName) !== '',
        )));
    }
}
