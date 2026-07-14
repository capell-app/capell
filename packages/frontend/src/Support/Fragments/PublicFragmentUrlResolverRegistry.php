<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Fragments;

use Capell\Frontend\Contracts\Fragments\PublicFragmentUrlResolver;
use Capell\Frontend\Data\Fragments\PublicFragmentReferenceData;
use Capell\Frontend\Exceptions\DuplicatePublicFragmentOwner;
use Capell\Frontend\Exceptions\PublicFragmentReferenceInvalid;
use InvalidArgumentException;

final class PublicFragmentUrlResolverRegistry
{
    /** @var array<string, PublicFragmentUrlResolver> */
    private array $resolvers = [];

    /**
     * @param  iterable<PublicFragmentUrlResolver>  $resolvers
     */
    public function __construct(iterable $resolvers)
    {
        foreach ($resolvers as $resolver) {
            $owner = $resolver->owner();

            if (preg_match('/^[a-z0-9][a-z0-9._-]*$/', $owner) !== 1) {
                throw new InvalidArgumentException('Public fragment resolver owners must use lowercase stable identifiers.');
            }

            if (array_key_exists($owner, $this->resolvers)) {
                throw new DuplicatePublicFragmentOwner($owner);
            }

            $this->resolvers[$owner] = $resolver;
        }
    }

    /** @return list<string> */
    public function owners(): array
    {
        return array_keys($this->resolvers);
    }

    public function hasResolvers(): bool
    {
        return $this->resolvers !== [];
    }

    public function has(string $owner): bool
    {
        return array_key_exists($owner, $this->resolvers);
    }

    public function url(PublicFragmentReferenceData $reference): string
    {
        $resolver = $this->resolvers[$reference->owner] ?? null;

        if (! $resolver instanceof PublicFragmentUrlResolver) {
            throw new PublicFragmentReferenceInvalid;
        }

        $url = $resolver->url($reference);

        if ($url === '') {
            throw new PublicFragmentReferenceInvalid;
        }

        return $url;
    }
}
