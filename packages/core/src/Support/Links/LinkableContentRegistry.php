<?php

declare(strict_types=1);

namespace Capell\Core\Support\Links;

use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Contracts\LinkableContent;
use Capell\Core\Data\LinkableContentData;
use Capell\Core\Enums\ExtensionContributionReceiptType;
use Capell\Core\Support\Registries\AbstractKeyedRegistry;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** @extends AbstractKeyedRegistry<LinkableContent> */
final class LinkableContentRegistry extends AbstractKeyedRegistry
{
    public function register(LinkableContent $provider): self
    {
        $key = $provider->key();

        throw_if($key === '', InvalidArgumentException::class, 'Linkable content provider keys cannot be empty.');

        $this->setItem($key, $provider);
        if (app()->bound(RecordsExtensionContributionReceipt::class)) {
            $this->recordReceipt($key, $provider::class);
        }

        return $this;
    }

    public function provider(string $key): ?LinkableContent
    {
        throw_if($key === '', InvalidArgumentException::class, 'Linkable content provider keys cannot be empty.');

        return $this->getItem($key);
    }

    public function has(string $key): bool
    {
        return $this->hasItem($key);
    }

    /**
     * @return Collection<string, LinkableContent>
     */
    public function all(): Collection
    {
        return collect($this->allItems());
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

    public function clear(): void
    {
        $this->clearItems();
    }

    /**
     * Registration can run from a callback deferred until another package's
     * provider resolves this registry, so the active context may belong to
     * that package. Own the receipt by the provider's declaring package
     * instead. An anonymous class has no declaring namespace (its name derives
     * from the interface it implements), so it keeps the active context.
     */
    private function recordReceipt(string $key, string $implementation): void
    {
        $receipts = resolve(RecordsExtensionContributionReceipt::class);

        if (str_contains($implementation, '@anonymous')) {
            $receipts->recordContribution(ExtensionContributionReceiptType::LinkableContent, 'linkable-content:' . $key, $implementation, self::class, 'runtime');

            return;
        }

        $receipts->recordContributionFromSource(ExtensionContributionReceiptType::LinkableContent, 'linkable-content:' . $key, $implementation, $implementation, 'runtime');
    }
}
