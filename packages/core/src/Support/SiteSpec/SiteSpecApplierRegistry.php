<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Capell\Core\Contracts\SiteSpec\SiteSpecApplier;
use Capell\Core\Data\SiteSpec\CapellSiteSpecData;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Contracts\Container\Container;
use LogicException;
use RuntimeException;

/** @extends AbstractKeyedRegistry<SiteSpecApplier> */
final class SiteSpecApplierRegistry extends AbstractKeyedRegistry
{
    private bool $taggedAppliersDiscovered = false;

    public function __construct(private readonly Container $container) {}

    public function register(SiteSpecApplier $applier): void
    {
        $key = $applier->key();

        throw_if($key === '', LogicException::class, 'Site spec applier keys must not be empty.');
        throw_if($this->hasItem($key), LogicException::class, sprintf('A site spec applier is already registered for [%s].', $key));

        $this->setItem($key, $applier);
    }

    public function has(string $key): bool
    {
        $this->discoverTaggedAppliers();

        return $this->hasItem($key);
    }

    /**
     * @param  array<string, Page>  $pagesBySlug
     */
    public function apply(string $key, CapellSiteSpecData $spec, Site $site, array $pagesBySlug): void
    {
        $this->discoverTaggedAppliers();

        $applier = $this->getItem($key);

        throw_unless($applier instanceof SiteSpecApplier, RuntimeException::class, sprintf(
            'The site spec requires an installed package to register the [%s] applier.',
            $key,
        ));

        $applier->apply($spec, $site, $pagesBySlug);
    }

    /** @return list<string> */
    public function keys(): array
    {
        $this->discoverTaggedAppliers();

        $keys = array_keys($this->allItems());
        sort($keys);

        return $keys;
    }

    private function discoverTaggedAppliers(): void
    {
        if ($this->taggedAppliersDiscovered) {
            return;
        }

        $this->taggedAppliersDiscovered = true;

        foreach ($this->container->tagged(SiteSpecApplier::TAG) as $applier) {
            if ($applier instanceof SiteSpecApplier) {
                $this->register($applier);
            }
        }
    }
}
