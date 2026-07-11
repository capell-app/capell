<?php

declare(strict_types=1);

namespace Capell\Core\Concerns;

use Capell\Core\Data\DefaultPageData;
use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;

trait HasDefaultPages
{
    /**
     * @var Collection<string, DefaultPageData>
     */
    protected ?Collection $defaultPages = null;

    /**
     * @return Collection<string, DefaultPageData>
     */
    public function loadDefaultPages(): Collection
    {
        if (! $this->defaultPages instanceof Collection) {
            $defaultPageKeys = config('capell.default_pages', []);
            $defaultPageKeys = is_array($defaultPageKeys) ? array_filter($defaultPageKeys, is_string(...)) : [];

            $this->defaultPages = collect($defaultPageKeys)
                ->mapWithKeys(fn (string $key): array => [
                    $key => new DefaultPageData(
                        key: $key,
                        label: str($key)->title()->toString(),
                    ),
                ]);
        }

        return $this->defaultPages;
    }

    /**
     * @return Collection<string, DefaultPageData>
     */
    public function getDefaultPages(): Collection
    {
        return $this->loadDefaultPages();
    }

    public function getDefaultPage(string $key): DefaultPageData
    {
        $defaultPages = $this->loadDefaultPages();

        return $defaultPages[$key] ?? throw new InvalidArgumentException(sprintf('Default page with key %s not found.', $key));
    }

    public function addDefaultPage(string $key, string $label, Closure $callback): self
    {
        $defaultPages = $this->loadDefaultPages();

        $defaultPages->put($key, new DefaultPageData(
            key: $key,
            label: $label,
            callback: $callback,
        ));

        return $this;
    }
}
