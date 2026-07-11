<?php

declare(strict_types=1);

use Capell\Admin\Actions\Upgrade\CheckCapellApiForUpdatesAction;
use Capell\Admin\Tests\Support\MarketplaceUpdateAdvisorySnapshotTable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    MarketplaceUpdateAdvisorySnapshotTable::ensure(truncate: true);
    config([
        'capell-admin.upgrades.api_url' => 'https://capell.test/api/updates/check',
        'capell-admin.upgrades.api_enabled' => true,
        'capell-admin.upgrades.enforce_https' => true,
    ]);
});

it('posts installed package versions to the configured Capell API and records the response', function (): void {
    Http::fake([
        'https://capell.test/api/updates/check' => Http::response([
            'data' => [
                'capell_version' => '4.2.0',
                'updates' => [
                    [
                        'notice_id' => 'capell-4-3-0',
                        'composer_name' => 'capell-app/capell',
                        'installed_version' => '4.2.0',
                        'recommended_version' => '4.3.0',
                        'type' => 'feature',
                        'severity' => 'low',
                    ],
                ],
                'advisories' => [],
                'response_id' => 'response-123',
            ],
        ], 200),
    ]);

    $wasSuccessful = CheckCapellApiForUpdatesAction::run();

    expect($wasSuccessful)->toBeTrue()
        ->and(DB::table('marketplace_update_advisory_snapshots')->where('source', 'capell-api')->exists())->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        $payload = $request->data();

        return $request->url() === 'https://capell.test/api/updates/check'
            && $request->method() === 'POST'
            && isset($payload['installed_packages'])
            && is_array($payload['installed_packages']);
    });
});

it('does not call non-https endpoints when https enforcement is enabled', function (): void {
    config(['capell-admin.upgrades.api_url' => 'http://capell.test/api/updates/check']);
    Http::fake();

    expect(CheckCapellApiForUpdatesAction::run())->toBeFalse();

    Http::assertNothingSent();
});

it('falls back safely when the snapshot table is missing', function (): void {
    Schema::dropIfExists('marketplace_update_advisory_snapshots');
    Http::fake([
        'https://capell.test/api/updates/check' => Http::response(['data' => ['updates' => [], 'advisories' => []]], 200),
    ]);

    expect(CheckCapellApiForUpdatesAction::run())->toBeTrue();
});
