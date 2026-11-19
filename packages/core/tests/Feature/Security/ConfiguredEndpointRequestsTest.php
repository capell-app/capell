<?php

declare(strict_types=1);

use Capell\Core\Actions\Marketplace\SendExtensionHealthReportAction;
use Capell\Core\Models\Site;
use Capell\Core\Support\Marketplace\NullExtensionEntitlements;
use Capell\Core\Testing\Http\FakeHostAddressResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Requests to endpoints an operator configured carry a registration token,
// instance identity or signed payload. They must go over https only, to a
// public host, and never follow a redirect to wherever the endpoint points.

beforeEach(function (): void {
    FakeHostAddressResolver::bind();
});

dataset('configured endpoint callers', [
    'cloud registration' => [
        static function (string $baseUrl): void {
            config()->set('capell.cloud.install_mode', 'cloud');
            config()->set('capell.cloud.registration_url', $baseUrl . '/api/v1/cloud-instances/1/register');
            config()->set('capell.cloud.registration_token', str_repeat('c', 64));
            config()->set('capell.cloud.site_url', 'https://acme.laravel.cloud');
            Site::factory()->create();

            artisanCommand('capell:cloud-bootstrap')->run();
        },
        '/api/v1/cloud-instances/1/register',
    ],
    'marketplace heartbeat' => [
        static function (string $baseUrl): void {
            config()->set('capell-marketplace.marketplace.base_url', $baseUrl);

            try {
                SendExtensionHealthReportAction::run(source: 'heartbeat');
            } catch (RuntimeException) {
                // A refused or redirected heartbeat fails; the assertions are about what was sent.
            }
        },
        '/instances/heartbeat',
    ],
    'unsigned licence decision' => [
        static function (string $baseUrl): void {
            config()->set('capell-marketplace.marketplace.base_url', $baseUrl);

            try {
                (new NullExtensionEntitlements)->licenceDecision('acme-forms', 'install', 'example.com');
            } catch (RuntimeException) {
                // As above.
            }
        },
        '/extensions/acme-forms/licence-decision',
    ],
]);

it('sends nothing to a configured endpoint over plain http', function (Closure $call): void {
    Http::fake();

    $call('http://capell.example');

    Http::assertNothingSent();
})->with('configured endpoint callers');

it('does not follow a configured endpoint that redirects elsewhere', function (Closure $call, string $path): void {
    Http::fake([
        'capell.example/*' => Http::response('', 307, ['Location' => 'https://collector.example/capture']),
        'collector.example/*' => Http::response(['data' => []]),
    ]);

    $call('https://capell.example');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://capell.example' . $path);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), 'collector.example'));
})->with('configured endpoint callers');

it('refuses a configured endpoint whose host resolves to a private address', function (Closure $call): void {
    FakeHostAddressResolver::bind(['capell.example' => ['10.0.0.8']]);
    Http::fake();

    $call('https://capell.example');

    Http::assertNothingSent();
})->with('configured endpoint callers');
