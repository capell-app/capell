<?php

declare(strict_types=1);

use Capell\Core\Events\CapellDoctorPassed;
use Capell\Marketplace\Actions\RunMarketplaceHeartbeatAction;
use Capell\Marketplace\Models\MarketplaceInstance;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'app.url' => 'https://example.test',
        'capell-marketplace.marketplace.base_url' => 'https://capell.test/api/v1',
        'capell-marketplace.marketplace.webhook_url' => 'https://example.test/capell/marketplace/webhook',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => '00000000-0000-4000-8000-000000000001',
        'signing_secret_encrypted' => 'test-signing-secret',
        'connection_metadata' => [],
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://capell.test/api/v1/instances/heartbeat' => Http::response([
            'data' => ['instance_id' => '00000000-0000-4000-8000-000000000001', 'updates' => [], 'advisories' => []],
        ]),
    ]);
});

/**
 * @return list<array<array-key, mixed>>
 */
function sentHeartbeatPayloads(): array
{
    $payloads = [];

    foreach (Http::recorded() as [$request]) {
        if ($request instanceof Request) {
            $payloads[] = $request->data();
        }
    }

    return $payloads;
}

it('records and sends nothing when the site has not opted in', function (): void {
    config(['capell-marketplace.heartbeat.share_setup_milestones' => false]);

    event(new CapellDoctorPassed);
    RunMarketplaceHeartbeatAction::run();

    expect(MarketplaceInstance::query()->firstOrFail()->connection_metadata)->not->toHaveKey('first_doctor_passed_at')
        ->and(sentHeartbeatPayloads()[0])->not->toHaveKey('milestones');
});

it('sends the first passing doctor run once, as a timestamp only, when opted in', function (): void {
    config(['capell-marketplace.heartbeat.share_setup_milestones' => true]);
    $this->travelTo('2026-11-14 10:00:00');

    event(new CapellDoctorPassed);
    $this->travelTo('2026-11-14 11:00:00');
    event(new CapellDoctorPassed);

    RunMarketplaceHeartbeatAction::run();
    RunMarketplaceHeartbeatAction::run();

    $payloads = sentHeartbeatPayloads();

    expect($payloads)->toHaveCount(2)
        ->and($payloads[0]['milestones'])->toBe(['first_doctor_passed_at' => '2026-11-14T10:00:00+00:00'])
        ->and($payloads[1])->not->toHaveKey('milestones');
});
