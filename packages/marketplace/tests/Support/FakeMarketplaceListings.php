<?php

declare(strict_types=1);

namespace Capell\Marketplace\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A retry asks the Marketplace to authorise the operation again, so a test that
 * retries needs a Marketplace to ask. This answers the exact-lookup request with
 * a free listing for whichever packages were asked about, so the retry is
 * re-authorised locally and the test stays about what it is testing.
 */
final class FakeMarketplaceListings
{
    public static function free(string $latestVersion = '9.9.9'): void
    {
        Http::fake([
            '*extensions/by-composer*' => fn (Request $request) => Http::response([
                'data' => array_map(
                    static fn (string $composerName): array => [
                        'slug' => str($composerName)->after('/')->toString(),
                        'name' => $composerName,
                        'composer_name' => $composerName,
                        'kind' => 'tool',
                        'price_cents' => 0,
                        'is_paid' => false,
                        'latest_version' => $latestVersion,
                        'install_eligibility' => [
                            'state' => 'authorized',
                            'can_install' => true,
                            'can_update' => true,
                            'can_run_existing' => true,
                        ],
                    ],
                    explode(',', (string) ($request->data()['composer_names'] ?? '')),
                ),
            ]),
        ]);
    }
}
