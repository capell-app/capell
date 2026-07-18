<?php

declare(strict_types=1);

namespace Capell\Core\Support\Links;

use Capell\Core\Contracts\LinkableContent;
use Capell\Core\Data\LinkableContentData;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class LinkableContentRegistry
{
    /**
     * @var array<string, LinkableContent>
     */
    private array $providers = [];

    public function register(LinkableContent $provider): self
    {
        $key = $provider->key();

        throw_if($key === '', InvalidArgumentException::class, 'Linkable content provider keys cannot be empty.');

        $this->providers[$key] = $provider;

        return $this;
    }

    public function provider(string $key): ?LinkableContent
    {
        throw_if($key === '', InvalidArgumentException::class, 'Linkable content provider keys cannot be empty.');

        return $this->providers[$key] ?? null;
    }

    /**
     * @return Collection<string, LinkableContent>
     */
    public function all(): Collection
    {
        return collect($this->providers);
    }

    /**
     * @return Collection<int, LinkableContentData>
     */
    public function options(?int $siteId = null, ?int $languageId = null): Collection
    {
        return $this->all()
            ->flatMap(
                fn (LinkableContent $provider): Collection => $provider->options($siteId, $languageId),
            )
            ->values();
    }
}
