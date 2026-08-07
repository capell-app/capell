<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Marketplace\Data\ExtensionDetailData;
use Capell\Marketplace\Filament\Support\MarketplaceCatalogueRecordProvider;
use Illuminate\Support\Number;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class BuildMarketplaceSuitePresentationAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  list<array<string, mixed>>|null  $catalogueRecords
     * @return array{
     *   bundle: string,
     *   members: list<array{name: string, composer_name: string, price: string|null}>,
     *   combined_price: string,
     *   member_total: string|null,
     *   savings: string|null
     * }|null
     */
    public function handle(ExtensionDetailData $detail, ?array $catalogueRecords = null): ?array
    {
        if ($detail->productBundle === null || $detail->requiredDependencies === []) {
            return null;
        }

        $catalogueRecords ??= resolve(MarketplaceCatalogueRecordProvider::class)->records(
            includeLocalExtensionState: false,
        );
        $records = collect($catalogueRecords)
            ->filter(fn (mixed $record): bool => is_array($record))
            ->keyBy(fn (array $record): string => is_string($record['composer_name'] ?? null)
                ? $record['composer_name']
                : '');
        $allMemberPricesKnown = true;
        $memberTotalCents = 0;

        $members = array_map(function (string $composerName) use ($records, $detail, &$allMemberPricesKnown, &$memberTotalCents): array {
            $record = $records->get($composerName);
            $record = is_array($record) ? $record : [];

            $priceCents = $record['price_cents'] ?? null;
            $currency = $record['currency'] ?? null;
            $hasComparablePrice = is_numeric($priceCents) && $currency === $detail->currency;

            if ($hasComparablePrice) {
                $memberTotalCents += (int) $priceCents;
            } else {
                $allMemberPricesKnown = false;
            }

            return [
                'name' => is_string($record['name'] ?? null) ? $record['name'] : $composerName,
                'composer_name' => $composerName,
                'price' => $hasComparablePrice
                    ? (string) Number::currency((int) $priceCents / 100, $detail->currency)
                    : null,
            ];
        }, $detail->requiredDependencies);

        $savingsCents = $allMemberPricesKnown ? $memberTotalCents - $detail->priceCents : 0;

        return [
            'bundle' => $detail->productBundle,
            'members' => array_values($members),
            'combined_price' => (string) Number::currency($detail->priceCents / 100, $detail->currency),
            'member_total' => $allMemberPricesKnown
                ? (string) Number::currency($memberTotalCents / 100, $detail->currency)
                : null,
            'savings' => $savingsCents > 0
                ? (string) Number::currency($savingsCents / 100, $detail->currency)
                : null,
        ];
    }
}
