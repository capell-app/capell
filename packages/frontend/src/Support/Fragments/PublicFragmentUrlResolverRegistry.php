<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Fragments;

use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Capell\Frontend\Contracts\Fragments\PublicFragmentUrlResolver;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Capell\Frontend\Exceptions\DuplicatePublicFragmentOwner;
use Capell\Frontend\Exceptions\PublicFragmentReferenceInvalid;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<PublicFragmentUrlResolver> */
final class PublicFragmentUrlResolverRegistry extends AbstractKeyedRegistry
{
    /**
     * @param  iterable<PublicFragmentUrlResolver>  $resolvers
     */
    public function __construct(iterable $resolvers)
    {
        foreach ($resolvers as $resolver) {
            $owner = $resolver->owner();

            throw_if(preg_match('/^[a-z0-9][a-z0-9._-]*$/', $owner) !== 1, InvalidArgumentException::class, 'Public fragment resolver owners must use lowercase stable identifiers.');

            throw_if($this->hasItem($owner), DuplicatePublicFragmentOwner::class, $owner);

            $this->setItem($owner, $resolver);
        }
    }

    /** @return list<string> */
    public function owners(): array
    {
        return array_keys($this->allItems());
    }

    public function hasResolvers(): bool
    {
        return $this->allItems() !== [];
    }

    public function has(string $owner): bool
    {
        return $this->hasItem($owner);
    }

    public function url(PublicFragmentReferenceData $reference): string
    {
        $resolver = $this->getItem($reference->owner);

        throw_unless($resolver instanceof PublicFragmentUrlResolver, PublicFragmentReferenceInvalid::class);

        $url = $resolver->url($reference);

        throw_if($url === '', PublicFragmentReferenceInvalid::class);

        return $url;
    }
}
