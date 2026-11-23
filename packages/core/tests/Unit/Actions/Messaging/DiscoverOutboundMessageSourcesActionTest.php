<?php

declare(strict_types=1);

use Acme\OutboundAlerts\Mail\StockReportMail;
use Acme\OutboundAlerts\StockAlertNotification;
use Capell\Core\Actions\Messaging\DiscoverOutboundMessageSourcesAction;
use Capell\Core\Actions\Messaging\ResolveOutboundMessageOwnerAction;
use Capell\Core\Data\Messaging\OutboundMessageSourceData;
use Capell\Core\Enums\Messaging\OutboundMessageKind;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Support\Manifest\CapellManifestData;

if (! function_exists('outboundAlertsFixture')) {
    /** @return array<string, CapellManifestData> */
    function outboundAlertsFixture(): array
    {
        $fixtures = dirname(__DIR__, 3) . '/fixtures';
        $path = $fixtures . '/outbound-messages/acme-alerts';

        spl_autoload_register(static function (string $class) use ($path): void {
            $prefix = 'Acme\\OutboundAlerts\\';

            if (str_starts_with($class, $prefix)) {
                $file = $path . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

                if (is_file($file)) {
                    require_once $file;
                }
            }
        });

        $data = json_decode((string) file_get_contents($fixtures . '/manifest-v3/valid-premium-package.json'), true, flags: JSON_THROW_ON_ERROR);
        $data['name'] = 'acme/alerts';
        $data['namespace'] = 'Acme\\OutboundAlerts';

        return ['acme/alerts' => CapellManifestData::fromArray($data, $path)];
    }
}

it('lists an installed package notification and mailable before either is sent, owned by that package', function (): void {
    $manifests = outboundAlertsFixture();
    CapellCore::forcePackageInstalled('acme/alerts');

    $sources = collect(DiscoverOutboundMessageSourcesAction::run($manifests))
        ->filter(static fn (OutboundMessageSourceData $source): bool => $source->owningPackage === 'acme/alerts')
        ->keyBy(static fn (OutboundMessageSourceData $source): string => (string) $source->sourceClass);

    $notification = $sources->sole(static fn (OutboundMessageSourceData $source): bool => $source->sourceClass === StockAlertNotification::class);
    $mailable = $sources->sole(static fn (OutboundMessageSourceData $source): bool => $source->sourceClass === StockReportMail::class);

    expect($sources->keys()->sort()->values()->all())->toBe([
        StockReportMail::class,
        StockAlertNotification::class,
    ])
        ->and($notification->kind)->toBe(OutboundMessageKind::Notification)
        ->and($notification->channel)->toBeNull()
        ->and($notification->key())->toBe('notification|Acme\\OutboundAlerts\\StockAlertNotification|*')
        ->and($mailable->channel)->toBe('mail');
});

it('skips a package that is present but not installed', function (): void {
    $manifests = outboundAlertsFixture();
    CapellCore::forcePackageInstalled('acme/alerts', false);

    $owners = array_map(
        static fn (OutboundMessageSourceData $source): ?string => $source->owningPackage,
        DiscoverOutboundMessageSourcesAction::run($manifests),
    );

    expect($owners)->not->toContain('acme/alerts');
});

it('names the owning package by its declared namespace and the host application as app', function (): void {
    $manifests = outboundAlertsFixture();

    expect(ResolveOutboundMessageOwnerAction::run(StockAlertNotification::class, $manifests))->toBe('acme/alerts')
        ->and(ResolveOutboundMessageOwnerAction::run('Acme\\OutboundAlertsExtra\\Thing', $manifests))->toBeNull()
        ->and(ResolveOutboundMessageOwnerAction::run('App\\Notifications\\Welcome', $manifests))->toBe('app')
        ->and(ResolveOutboundMessageOwnerAction::run(null, $manifests))->toBeNull();
});

it('keys an unattributed raw send apart from every class', function (): void {
    expect(OutboundMessageSourceData::unattributed('mail')->key())->toBe('unattributed|-|mail');
});
