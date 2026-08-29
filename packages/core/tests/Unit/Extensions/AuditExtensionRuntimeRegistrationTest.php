<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Contracts\Extensions\RegistersExtensionBlueprintSubject;
use Capell\Core\Contracts\Extensions\RegistersExtensionOutboundEvent;
use Capell\Core\Data\Extensions\ExtensionContributionReceiptData;
use Capell\Core\Data\OutboundEventDefinitionData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\OutboundEventRegistry;

if (! function_exists('makeRuntimeRegistrationAuditPackage')) {
    /**
     * Write a throwaway package whose manifest declares the supplied contribution.
     *
     * @param  array<string, mixed>  $contribution  Contribution entry without its `class` key.
     */
    function makeRuntimeRegistrationAuditPackage(
        string $packageName,
        string $contractInterface,
        array $contribution,
    ): string {
        $directory = sys_get_temp_dir() . '/capell-runtime-registration-audit-' . bin2hex(random_bytes(6));
        $namespace = str($packageName)->after('/')->studly()->prepend('Vendor\\')->append('\\')->toString();
        $contributionClass = $namespace . 'Contributions\\PackageContribution';

        mkdir($directory . '/src/Contributions', 0755, true);
        mkdir($directory . '/src/Providers', 0755, true);

        file_put_contents($directory . '/composer.json', json_encode([
            'name' => $packageName,
            'autoload' => ['psr-4' => [$namespace => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        file_put_contents($directory . '/src/Providers/PackageServiceProvider.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}Providers;

use Illuminate\Support\ServiceProvider;

final class PackageServiceProvider extends ServiceProvider
{
}
PHP);

        file_put_contents($directory . '/src/Contributions/PackageContribution.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}Contributions;

final class PackageContribution implements \\{$contractInterface}
{
    public static function compatibleCapellApiVersion(): string
    {
        return '^1.0';
    }
}
PHP);

        $manifestContribution = [...$contribution, 'class' => $contributionClass, 'providerBucket' => 'runtime'];
        if (is_string($contribution['event'] ?? null)) {
            $manifestContribution['key'] = $contribution['event'];
        }

        file_put_contents($directory . '/capell.json', json_encode(
            capellManifestV3Array(
                name: $packageName,
                namespace: rtrim($namespace, '\\'),
                providers: ['runtime' => [$namespace . 'Providers\\PackageServiceProvider']],
                overrides: ['contributes' => [$manifestContribution]],
            ),
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ));

        return $directory;
    }
}

if (! function_exists('runtimeRegistrationAuditResults')) {
    /**
     * @return list<array{package: string, manifest_path: string, severity: string, message: string, context: array<string, mixed>}>
     */
    function runtimeRegistrationAuditResults(string $directory, string $message, array $buckets = []): array
    {
        return array_values(array_filter(
            AuditExtensionContractsAction::run($directory, $buckets),
            static fn (array $result): bool => $result['message'] === $message,
        ));
    }
}

function recordTestReceipt(ExtensionContributionReceiptRegistry $receipts, ExtensionContributionReceiptData $receipt): void
{
    $context = $receipt->foundationBuiltIn
        ? ExtensionContributionReceiptContext::foundation($receipt->ownerPackage, $receipt->providerBucket, $receipt->sourceClass)
        : ExtensionContributionReceiptContext::forPackage($receipt->ownerPackage, $receipt->providerBucket, $receipt->sourceClass);

    $receipts->withContext($context, function () use ($receipts, $receipt): void {
        $receipts->recordContribution(
            $receipt->type,
            $receipt->key,
            $receipt->implementation,
            $receipt->sourceClass,
            $receipt->providerBucket,
        );
    });
}

it('reconciles declared and loaded contributions only for an explicit booted context', function (): void {
    $outbound = new OutboundEventRegistry;
    $outbound->register(new OutboundEventDefinitionData(
        name: 'vendor-package.thing-happened',
        version: 1,
        payloadClass: OutboundEventDefinitionData::class,
        description: 'Test outbound event.',
        ownerPackage: 'vendor/receipt-match',
    ));
    app()->instance(OutboundEventRegistry::class, $outbound);

    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData(
        ownerPackage: 'vendor/receipt-match',
        providerBucket: 'runtime',
        type: ExtensionContributionType::OutboundEvent,
        key: 'vendor-package.thing-happened',
        implementation: OutboundEventDefinitionData::class,
        sourceClass: 'Vendor\\Receipt\\Provider',
    ));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);

    $directory = makeRuntimeRegistrationAuditPackage(
        'vendor/receipt-match',
        RegistersExtensionOutboundEvent::class,
        ['type' => 'outbound-event', 'event' => 'vendor-package.thing-happened'],
    );

    expect(AuditExtensionContractsAction::run($directory, ['runtime']))->toBe([]);
});

it('reports declared-only and loaded-only receipt drift', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);
    $declaredDirectory = makeRuntimeRegistrationAuditPackage('vendor/declared-only', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'vendor-package.missing']);
    expect(runtimeRegistrationAuditResults($declaredDirectory, 'Outbound event contribution is not registered at runtime.', ['runtime']))->toHaveCount(1);

    $loadedDirectory = makeRuntimeRegistrationAuditPackage('vendor/loaded-only', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'vendor-package.other']);
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/loaded-only', 'runtime', ExtensionContributionType::OutboundEvent, 'vendor-package.unlisted', OutboundEventDefinitionData::class, 'Vendor\\Provider'));
    expect(runtimeRegistrationAuditResults($loadedDirectory, 'Runtime contribution is not declared in the manifest.', ['runtime']))->toHaveCount(1);
});

it('reports wrong receipt owner and provider bucket with diagnostics', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/other', 'admin', ExtensionContributionType::OutboundEvent, 'vendor-package.event', OutboundEventDefinitionData::class, 'Vendor\\Provider'));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);
    $directory = makeRuntimeRegistrationAuditPackage('vendor/wrong-receipt', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'vendor-package.event']);
    $results = runtimeRegistrationAuditResults($directory, 'Runtime contribution has the wrong package owner.', ['runtime']);
    expect($results)->toHaveCount(1)->and($results[0]['context'])->toMatchArray(['expectedBucket' => 'runtime', 'actualBucket' => 'admin', 'contributionKey' => 'vendor-package.event']);
});

it('reports a receipt in the wrong provider bucket', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/wrong-bucket', 'admin', ExtensionContributionType::OutboundEvent, 'vendor-package.event', OutboundEventDefinitionData::class, 'Vendor\\Provider'));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);
    $directory = makeRuntimeRegistrationAuditPackage('vendor/wrong-bucket', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'vendor-package.event']);
    expect(runtimeRegistrationAuditResults($directory, 'Runtime contribution is registered in the wrong provider bucket.', ['runtime']))->toHaveCount(1);
});

it('reports swapped implementation identities even when owner and bucket match', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/swapped', 'runtime', ExtensionContributionType::OutboundEvent, 'vendor-package.event', stdClass::class, 'Vendor\\Provider'));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);
    $directory = makeRuntimeRegistrationAuditPackage('vendor/swapped', RegistersExtensionOutboundEvent::class, [
        'type' => 'outbound-event',
        'event' => 'vendor-package.event',
        'implementation' => OutboundEventDefinitionData::class,
    ]);
    $results = runtimeRegistrationAuditResults($directory, 'Runtime contribution has the wrong implementation.', ['runtime']);
    expect($results)->toHaveCount(1)->and($results[0]['context'])->toMatchArray(['expectedImplementation' => OutboundEventDefinitionData::class, 'actualImplementation' => stdClass::class, 'actualOwner' => 'vendor/swapped']);
});

it('does not report foundation built-ins as loaded-only drift', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('capell-app/core', 'runtime', ExtensionContributionType::Model, 'core.builtin', stdClass::class, 'Capell\\Core\\Providers\\CapellServiceProvider', true));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);
    $directory = makeRuntimeRegistrationAuditPackage('capell-app/core', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'core.declared']);
    $results = AuditExtensionContractsAction::run($directory, ['runtime']);
    expect($results)->toHaveCount(1)->and($results[0]['message'])->toBe('Outbound event contribution is not registered at runtime.');
});

it('does not audit a disabled package as though its current runtime role booted', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);
    $directory = makeRuntimeRegistrationAuditPackage('vendor/disabled-runtime', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'vendor-package.disabled']);
    $results = AuditExtensionContractsAction::run($directory);
    expect($results)->toBe([]);
});

it('keeps ownership for a deferred static registrar callback', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    $receipts->rememberProviderContext(
        StaticDeferredReceiptProbe::class,
        ExtensionContributionReceiptContext::forPackage('vendor/deferred', 'frontend', StaticDeferredReceiptProbe::class),
    );
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);

    StaticDeferredReceiptProbe::run();

    expect($receipts->forPackage('vendor/deferred')[0]->providerBucket)->toBe('frontend');
});

it('binds receipt ownership to trusted context rather than package input', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(RecordsExtensionContributionReceipt::class, $receipts);
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);

    $receipts->withContext(
        ExtensionContributionReceiptContext::forPackage('vendor/owned', 'runtime', 'Vendor\\Provider'),
        function (): void {
            resolve(RecordsExtensionContributionReceipt::class)->recordContribution(
                ExtensionContributionType::OutboundEvent,
                'vendor.owned.event',
                OutboundEventDefinitionData::class,
                'Vendor\\Provider',
            );
        },
    );

    expect($receipts->all()[0]->ownerPackage)->toBe('vendor/owned')
        ->and($receipts->all()[0]->foundationBuiltIn)->toBeFalse();
});

it('reconciles a trace key that is distinct from the marker metadata key', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData(
        'vendor/trace-link',
        'runtime',
        ExtensionContributionType::OutboundEvent,
        'vendor-package.runtime-key',
        'Vendor\\TraceLink\\Contributions\\PackageContribution',
        'Vendor\\TraceLink\\Providers\\PackageServiceProvider',
    ));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);
    $directory = makeRuntimeRegistrationAuditPackage('vendor/trace-link', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'vendor-package.marker-key']);
    $manifestPath = $directory . '/capell.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    $manifest['contributes'][0]['key'] = 'vendor-package.runtime-key';
    $manifest['contributes'][0]['providerBucket'] = 'runtime';
    unset($manifest['contributes'][0]['event']);
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

    expect(runtimeRegistrationAuditResults($directory, 'Declared contribution is not registered at runtime.', ['runtime']))->toBe([]);
});

const OUTBOUND_EVENT_WARNING = 'Outbound event contribution is not registered at runtime.';
const BLUEPRINT_SUBJECT_WARNING = 'Blueprint subject contribution is not registered at runtime.';

final class StaticDeferredReceiptProbe
{
    public static function run(): void
    {
        resolve(ExtensionContributionReceiptRegistry::class)->recordFromContext(
            ExtensionContributionType::RenderHook,
            'vendor.deferred.hook',
            self::class,
            self::class,
        );
    }
}

it('warns when a declared outbound event is not registered at runtime', function (): void {
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage(
        'vendor/outbound-missing',
        RegistersExtensionOutboundEvent::class,
        ['type' => 'outbound-event', 'event' => 'vendor-package.thing-happened'],
    );

    $results = runtimeRegistrationAuditResults($directory, OUTBOUND_EVENT_WARNING, ['runtime']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['severity'])->toBe('warning')
        ->and($results[0]['context'])->toBe(['event' => 'vendor-package.thing-happened']);
});

it('does not warn when a declared outbound event is registered at runtime', function (): void {
    $outboundEventRegistry = new OutboundEventRegistry;
    $outboundEventRegistry->register(new OutboundEventDefinitionData(
        name: 'vendor-package.thing-happened',
        version: 1,
        payloadClass: OutboundEventDefinitionData::class,
        description: 'Test outbound event.',
        ownerPackage: 'vendor/outbound-registered',
    ));
    app()->instance(OutboundEventRegistry::class, $outboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage(
        'vendor/outbound-registered',
        RegistersExtensionOutboundEvent::class,
        ['type' => 'outbound-event', 'events' => ['vendor-package.thing-happened']],
    );

    expect(runtimeRegistrationAuditResults($directory, OUTBOUND_EVENT_WARNING))->toBe([]);
});

it('warns when a declared blueprint subject is not registered at runtime', function (): void {
    app()->instance(BlueprintSubjectRegistry::class, new BlueprintSubjectRegistry);

    $directory = makeRuntimeRegistrationAuditPackage(
        'vendor/blueprint-missing',
        RegistersExtensionBlueprintSubject::class,
        ['type' => 'blueprint-subject', 'key' => 'vendor-package.collection'],
    );

    $results = runtimeRegistrationAuditResults($directory, BLUEPRINT_SUBJECT_WARNING, ['runtime']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['severity'])->toBe('warning')
        ->and($results[0]['context'])->toBe(['key' => 'vendor-package.collection']);
});

it('does not warn when a declared blueprint subject is registered at runtime', function (): void {
    $registeredKey = BlueprintSubjectEnum::Page->getKey();

    expect(resolve(BlueprintSubjectRegistry::class)->has($registeredKey))->toBeTrue();

    $directory = makeRuntimeRegistrationAuditPackage(
        'vendor/blueprint-registered',
        RegistersExtensionBlueprintSubject::class,
        ['type' => 'blueprint-subject', 'keys' => [$registeredKey]],
    );

    expect(runtimeRegistrationAuditResults($directory, BLUEPRINT_SUBJECT_WARNING))->toBe([]);
});
