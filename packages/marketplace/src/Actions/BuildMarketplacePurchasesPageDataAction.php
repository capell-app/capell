<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\Marketplace\ResolveExtensionLicenceDecisionAction;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Throwable;

final class BuildMarketplacePurchasesPageDataAction
{
    use AsFake;
    use AsObject;

    /**
     * @return array{
     *   purchases: list<array<string, mixed>>,
     *   installed: list<array<string, mixed>>,
     *   renewal_url: string|null,
     *   support_url: string|null,
     *   currency: string
     * }
     */
    public function handle(): array
    {
        $commercial = resolve(MarketplaceInstanceResolver::class)->latest()?->connection_metadata['commercial'] ?? [];
        $commercial = is_array($commercial) ? $commercial : [];

        return [
            'purchases' => $this->purchases($commercial),
            'installed' => $this->installedPaidExtensions(),
            'renewal_url' => $this->optionalString($commercial['renewal_url'] ?? null),
            'support_url' => $this->optionalString($commercial['support_url'] ?? null),
            'currency' => $this->optionalString($commercial['currency'] ?? null) ?? 'USD',
        ];
    }

    /** @param array<string, mixed> $commercial
     * @return list<array<string, mixed>>
     */
    private function purchases(array $commercial): array
    {
        $purchases = $commercial['purchases'] ?? [];

        return is_array($purchases)
            ? array_values(array_filter($purchases, is_array(...)))
            : [];
    }

    /** @return list<array<string, mixed>> */
    private function installedPaidExtensions(): array
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = is_string($domain) ? $domain : '';

        $extensions = CapellExtension::query()
            ->where('is_paid_marketplace_extension', true)
            ->orderBy('name')
            ->get()
            ->map(function (CapellExtension $extension) use ($domain): array {
                $status = is_string($extension->marketplace_runtime_status)
                    ? $extension->marketplace_runtime_status
                    : null;

                try {
                    $decision = ResolveExtensionLicenceDecisionAction::run(
                        $this->slug($extension),
                        'install',
                        $domain,
                    );
                    $status = $decision->licenceStatus->value;
                } catch (Throwable) {
                    // Heartbeat/local activation remains useful when the account
                    // service is temporarily unreachable. The page must not fail
                    // as a whole because one licence could not be refreshed.
                }

                $status = is_string($status) && $status !== '' ? $status : 'unverified';

                return [
                    'composer_name' => $extension->composer_name,
                    'name' => $extension->name ?? $extension->composer_name,
                    'status' => $status,
                    'checked_at' => $extension->marketplace_activation_checked_at,
                ];
            })
            ->all();

        return array_values($extensions);
    }

    private function slug(CapellExtension $extension): string
    {
        $metadataSlug = $extension->metadata['marketplace_slug'] ?? null;

        if (is_string($metadataSlug) && $metadataSlug !== '') {
            return $metadataSlug;
        }

        return str($extension->composer_name)->after('/')->toString();
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
