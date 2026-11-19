<?php

declare(strict_types=1);

namespace Capell\Core\Support\Marketplace;

use Carbon\CarbonImmutable;

/** @internal A verified catalogue selected for a policy decision. */
final readonly class CommercialCatalogue
{
    public CarbonImmutable $expiresAt;

    /** @param array<string, mixed> $document */
    public function __construct(public array $document, private bool $bundled = false)
    {
        $this->expiresAt = CarbonImmutable::parse($document['valid_until']);
    }

    public function classification(string $composerName): ?bool
    {
        return $this->listedClassification($composerName) ?? ($this->bundled ? true : null);
    }

    public function isListed(string $composerName): bool
    {
        return $this->listedClassification($composerName) !== null;
    }

    /**
     * @return list<string> Paid namespaces normalised by InstalledPackageCodeIdentity
     */
    public function paidNamespaces(): array
    {
        $namespaces = [];
        foreach ($this->document['packages'] as $entry) {
            if ($entry['is_paid'] !== true) {
                continue;
            }

            if (! is_array($entry['namespaces'] ?? null)) {
                continue;
            }

            foreach ($entry['namespaces'] as $namespace) {
                $normalised = is_string($namespace) ? InstalledPackageCodeIdentity::namespace($namespace) : '';
                // A single-segment prefix ('Acme\\') is shared by unrelated vendors, and an empty one
                // claims every class, so neither can mark code as paid.
                if (substr_count($normalised, '\\') >= 2) {
                    $namespaces[$normalised] = true;
                }
            }
        }

        return array_keys($namespaces);
    }

    private function listedClassification(string $composerName): ?bool
    {
        $name = strtolower($composerName);
        foreach ($this->document['packages'] as $entry) {
            if (strtolower($entry['composer_name']) === $name) {
                return $entry['is_paid'];
            }
        }

        return null;
    }
}
