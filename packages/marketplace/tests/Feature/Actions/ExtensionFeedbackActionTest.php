<?php

declare(strict_types=1);

use Capell\Marketplace\Actions\SubmitExtensionFeedbackAction;
use Capell\Marketplace\Data\ExtensionFeedbackData;
use Capell\Marketplace\Models\MarketplaceInstance;
use Illuminate\Support\Facades\Http;

it('submits rating-only feedback when the licence decision can rate', function (): void {
    config([
        'app.url' => 'https://client.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
    ]);

    MarketplaceInstance::query()->create([
        'instance_id' => 'instance-123',
        'signing_secret_encrypted' => 'secret-value',
        'last_heartbeat_at' => now(),
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite/licence-decision' => Http::response([
            'data' => [
                'licence_status' => 'active',
                'can_comment' => false,
                'can_rate' => true,
            ],
        ]),
        'https://marketplace.test/api/extensions/seo-suite/feedback' => Http::response([
            'data' => ['status' => 'pending'],
        ]),
    ]);

    $result = SubmitExtensionFeedbackAction::run(new ExtensionFeedbackData(
        slug: 'seo-suite',
        rating: 5,
        comment: null,
        tip: null,
        domain: 'client.test',
    ));

    expect($result['status'])->toBe('pending');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/seo-suite/licence-decision'
        && $request->data()['action'] === 'rate');
    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/seo-suite/feedback'
        && $request->data()['rating'] === 5);
});

it('blocks comment feedback when the licence decision can only rate', function (): void {
    config([
        'app.url' => 'https://client.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test/api',
    ]);

    Http::fake([
        'https://marketplace.test/api/extensions/seo-suite/licence-decision' => Http::response([
            'data' => [
                'licence_status' => 'active',
                'can_comment' => false,
                'can_rate' => true,
                'reason' => 'ratings_only',
            ],
        ]),
        'https://marketplace.test/api/extensions/seo-suite/feedback' => Http::response([
            'data' => ['status' => 'pending'],
        ]),
    ]);

    expect(fn (): array => SubmitExtensionFeedbackAction::run(new ExtensionFeedbackData(
        slug: 'seo-suite',
        rating: 5,
        comment: 'Useful extension.',
        tip: null,
        domain: 'client.test',
    )))->toThrow(RuntimeException::class, 'ratings_only');

    Http::assertNotSent(fn ($request): bool => $request->url() === 'https://marketplace.test/api/extensions/seo-suite/feedback');
});
