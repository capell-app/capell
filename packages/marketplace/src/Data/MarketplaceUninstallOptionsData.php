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
        );
    }

    /**
     * @return array{delete_package: bool, delete_data: bool}
     */
    public function toArray(): array
    {
        return [
            'delete_package' => $this->deletePackage,
            'delete_data' => $this->deleteData,
        ];
    }
}
