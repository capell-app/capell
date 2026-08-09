<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\Marketplace\ResolveExtensionLicenceDecisionAction;
use Capell\Core\Models\CapellExtension;
use Capell\Marketplace\Support\MarketplaceInstanceResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
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
     *   membership: array<string, mixed>|null,
     *   membership_price: string|null,
     *   membership_renewal_price: string|null,
     *   new_membership_product_count: int,
     *   priority_support_price: string|null,
     *   expired_explanation: string|null,
     *   currency: string|null
     * }
     */
    public function handle(): array
    {
        $commercial = resolve(MarketplaceInstanceResolver::class)->latest()?->connection_metadata['commercial'] ?? [];
        $commercial = is_array($commercial) ? $commercial : [];

        $membership = is_array($commercial['membership_comparison'] ?? null)
            ? $commercial['membership_comparison']
            : null;
        $currency = $this->optionalString($commercial['currency'] ?? data_get($membership, 'currency'));

        return [
            'purchases' => $this->purchases($commercial),
            'installed' => $this->installedPaidExtensions(),
            'renewal_url' => $this->optionalString($commercial['renewal_url'] ?? null),
            'support_url' => $this->optionalString($commercial['support_url'] ?? null),
            'membership' => $membership,
            'membership_price' => $this->money($membership['price_cents'] ?? null, $currency),
            'membership_renewal_price' => $this->money($membership['renewal_price_cents'] ?? null, $currency),
            'new_membership_product_count' => is_numeric($commercial['new_membership_product_count'] ?? null)
                ? (int) $commercial['new_membership_product_count']
                : 0,
            'priority_support_price' => $this->money($commercial['priority_support_price_cents'] ?? null, $currency),
            'expired_explanation' => $this->optionalString($commercial['expired_explanation'] ?? null),
            'currency' => $currency,
        ];
    }

    /** @param array<string, mixed> $commercial
     * @return list<array<string, mixed>>
     */
    private function purchases(array $commercial): array
    {
        $purchases = $commercial['purchases'] ?? [];

        if (! is_array($purchases)) {
            return [];
        }

        return array_values(array_filter(array_map(function (mixed $purchase): ?array {
            if (! is_array($purchase)) {
                return null;
            }

            if (array_key_exists('access_ends_at', $purchase)) {
                $purchase['access_ends_at'] = $this->safeDate($purchase['access_ends_at']);
            }

            return $purchase;
        }, $purchases)));
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

    private function money(mixed $cents, ?string $currency): ?string
    {
        if (! is_numeric($cents) || $currency === null) {
            return null;
        }

        return (string) Number::currency((int) $cents / 100, $currency);
    }

    private function safeDate(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toAtomString();
        } catch (Throwable) {
            return null;
        }
    }
}
