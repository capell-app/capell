<?php

declare(strict_types=1);

use Capell\Core\Actions\Marketplace\ResolveExtensionLicenceDecisionAction;
use Capell\Core\Contracts\Marketplace\ExtensionEntitlements;
use Capell\Core\Data\Marketplace\ExtensionLicenceDecisionData;
use Capell\Core\Enums\ExtensionLicenceStatus;
use Capell\Core\Models\CapellExtension;
use Capell\Core\Support\Marketplace\NullExtensionEntitlements;
use Illuminate\Support\Facades\Http;

it('resolves extension licence decisions through the bound extension entitlements', function (): void {
    app()->bind(ExtensionEntitlements::class, fn (): ExtensionEntitlements => new class implements ExtensionEntitlements
    {
        public function licenceDecision(string $slug, string $action, string $domain): ExtensionLicenceDecisionData
        {
            expect($slug)->toBe('forms')
                ->and($action)->toBe('install')
                ->and($domain)->toBe('example.test');

            return new ExtensionLicenceDecisionData(
                licenceStatus: ExtensionLicenceStatus::Active,
                canViewPrivateDocs: true,
                canDownload: true,
                canInstall: true,
                canUpdate: false,
                canRate: true,
                canComment: true,
                runtimeAllowed: true,
                verifiedSiteId: 'site-123',
                installId: 'install-123',
            );
        }

        public function verifyActivation(CapellExtension $extension, array $installedReceipt): bool
        {
            return false;
        }
    });

    $decision = ResolveExtensionLicenceDecisionAction::run('forms', 'install', 'example.test');

    expect($decision->licenceStatus)->toBe(ExtensionLicenceStatus::Active)
        ->and($decision->canInstall)->toBeTrue()
        ->and($decision->verifiedSiteId)->toBe('site-123')
        ->and($decision->installId)->toBe('install-123');
});

it('falls back to the marketplace HTTP endpoint when no marketplace package provides entitlements', function (): void {
    app()->bind(ExtensionEntitlements::class, NullExtensionEntitlements::class);

    config([
        'app.url' => 'https://customer.test',
        'capell-marketplace.marketplace.base_url' => 'https://marketplace.test',
        'capell-marketplace.instance.id' => null,
        'capell-marketplace.marketplace.webhook_secret' => null,
    ]);

    Http::fake([
        'https://marketplace.test/extensions/forms/licence-decision' => Http::response([
            'data' => [
                'licence_status' => 'active',
                'can_download' => true,
                'can_install' => true,
                'can_update' => true,
                'runtime_allowed' => true,
                'reason' => 'allowed',
                'verified_site_id' => 'site-http',
                'install_id' => 'install-http',
                'signed_activation' => ['signature' => 'abc'],
            ],
        ]),
    ]);

    $decision = ResolveExtensionLicenceDecisionAction::run('forms', 'install', 'example.test');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://marketplace.test/extensions/forms/licence-decision'
        && $request['action'] === 'install'
        && $request['domain'] === 'example.test'
        && $request['app_url'] === 'https://customer.test');

    expect($decision->licenceStatus)->toBe(ExtensionLicenceStatus::Active)
        ->and($decision->canDownload)->toBeTrue()
        ->and($decision->canUpdate)->toBeTrue()
        ->and($decision->runtimeAllowed)->toBeTrue()
        ->and($decision->reason)->toBe('allowed')
        ->and($decision->signedActivation)->toBe(['signature' => 'abc']);
});

it('fails loudly when the fallback marketplace endpoint cannot resolve a licence decision', function (): void {
    app()->bind(ExtensionEntitlements::class, NullExtensionEntitlements::class);

    config([
        'capell-marketplace.instance.id' => null,
        'capell-marketplace.marketplace.webhook_secret' => null,
        'capell-marketplace.marketplace.base_url' => null,
    ]);

    expect(fn (): ExtensionLicenceDecisionData => ResolveExtensionLicenceDecisionAction::run('forms', 'install', 'example.test'))
        ->toThrow(RuntimeException::class, 'The marketplace URL is not configured.');

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace-error.test']);

    Http::fake([
        'https://marketplace-error.test/extensions/forms/licence-decision' => Http::response(['message' => 'Nope'], 503),
    ]);

    expect(fn (): ExtensionLicenceDecisionData => ResolveExtensionLicenceDecisionAction::run('forms', 'install', 'example.test'))
        ->toThrow(RuntimeException::class, 'Marketplace could not resolve this licence decision.');

    config(['capell-marketplace.marketplace.base_url' => 'https://marketplace-data.test']);

    Http::fake([
        'https://marketplace-data.test/extensions/forms/licence-decision' => Http::response(['data' => 'not-an-array']),
    ]);

    expect(fn (): ExtensionLicenceDecisionData => ResolveExtensionLicenceDecisionAction::run('forms', 'install', 'example.test'))
        ->toThrow(RuntimeException::class, 'The marketplace licence decision response did not include a data object.');
});

it('never verifies an installed receipt without a marketplace package', function (): void {
    expect((new NullExtensionEntitlements)->verifyActivation(new CapellExtension, ['composer_name' => 'capell-app/forms', 'signature' => 'signed']))
        ->toBeFalse();
});
