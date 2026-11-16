<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Contracts\ContentGraph\ContentGraphExtractor;
use Capell\Core\Contracts\Extensions\PreparesExtensionContributionReceipts;
use Capell\Core\Contracts\Extensions\RecordsExtensionContributionReceipt;
use Capell\Core\Contracts\Extensions\RegistersExtensionBlueprintSubject;
use Capell\Core\Contracts\Extensions\RegistersExtensionOutboundEvent;
use Capell\Core\Contracts\Extensions\RegistersExtensionPageType;
use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;
use Capell\Core\Contracts\Extensions\RunsScheduledExtensionJob;
use Capell\Core\Data\Extensions\ExtensionContributionReceiptData;
use Capell\Core\Data\OutboundEventDefinitionData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Enums\ExtensionContributionReceiptType;
use Capell\Core\Enums\ExtensionContributionType;
use Capell\Core\Providers\CapellServiceProvider;
use Capell\Core\Support\BlueprintSubjectRegistry;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptContext;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\OutboundEventRegistry;
use Capell\Core\Tests\Support\AuditUnlistedContentGraphExtractor;
use Capell\Core\Tests\Support\StaticDeferredReceiptProbe;

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

        $extraMethods = '';
        if ($contractInterface === ContentGraphExtractor::class) {
            $extraMethods = <<<'PHP'

    public static function sourceModel(): string
    {
        return \Illuminate\Database\Eloquent\Model::class;
    }

    public function extract(\Illuminate\Database\Eloquent\Model $model): \Capell\Core\Data\ContentGraph\ContentGraphEdgeCollectionData
    {
        throw new \RuntimeException('Test extractor should not run.');
    }
PHP;
        }

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
{$extraMethods}
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

it('does not report a matched declaration again for duplicate receipts', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData(
        'vendor/duplicate-receipts',
        'runtime',
        ExtensionContributionType::OutboundEvent,
        'vendor-package.duplicate',
        OutboundEventDefinitionData::class,
        'Vendor\\Provider',
    ));
    recordTestReceipt($receipts, new ExtensionContributionReceiptData(
        'vendor/duplicate-receipts',
        'runtime',
        ExtensionContributionType::OutboundEvent,
        'vendor-package.duplicate',
        stdClass::class,
        'Vendor\\SecondaryProvider',
    ));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);

    $outboundEvents = new OutboundEventRegistry;
    $outboundEvents->register(new OutboundEventDefinitionData(
        name: 'vendor-package.duplicate',
        version: 1,
        payloadClass: OutboundEventDefinitionData::class,
        description: 'Test duplicate outbound event.',
        ownerPackage: 'vendor/duplicate-receipts',
    ));
    app()->instance(OutboundEventRegistry::class, $outboundEvents);

    $directory = makeRuntimeRegistrationAuditPackage(
        'vendor/duplicate-receipts',
        RegistersExtensionOutboundEvent::class,
        [
            'type' => 'outbound-event',
            'event' => 'vendor-package.duplicate',
            'implementation' => OutboundEventDefinitionData::class,
        ],
    );

    expect(AuditExtensionContractsAction::run($directory, ['runtime']))->toBe([]);
});

it('does not require receipt-only identities in a manifest', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData(
        'vendor/receipt-only',
        'runtime',
        ExtensionContributionReceiptType::Subscriber,
        'subscriber:Vendor\\Subscriber',
        'Vendor\\Subscriber',
        'Vendor\\Provider',
    ));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    $outboundEvents = new OutboundEventRegistry;
    $outboundEvents->register(new OutboundEventDefinitionData(
        name: 'vendor-package.declared',
        version: 1,
        payloadClass: OutboundEventDefinitionData::class,
        description: 'Test declared outbound event.',
        ownerPackage: 'vendor/receipt-only',
    ));
    app()->instance(OutboundEventRegistry::class, $outboundEvents);

    $directory = makeRuntimeRegistrationAuditPackage(
        'vendor/receipt-only',
        RegistersExtensionOutboundEvent::class,
        ['type' => 'outbound-event', 'event' => 'vendor-package.declared'],
    );

    expect(AuditExtensionContractsAction::run($directory, ['runtime']))->toBe([]);
});

it('does not synthesise a receipt identity for a keyless route contribution', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage(
        'vendor/keyless-route',
        RegistersExtensionRoute::class,
        ['type' => 'route'],
    );

    expect(AuditExtensionContractsAction::run($directory, ['runtime']))->toBe([]);
});

it('reconciles ContentGraphRegistry receipts and reports undeclared extractors', function (): void {
    $packageName = 'vendor/content-graph-receipts';
    $directory = makeRuntimeRegistrationAuditPackage($packageName, ContentGraphExtractor::class, ['type' => 'content-graph']);
    $namespace = str($packageName)->after('/')->studly()->prepend('Vendor\\')->append('\\')->toString();
    $contributionClass = $namespace . 'Contributions\\PackageContribution';
    require_once $directory . '/src/Contributions/PackageContribution.php';

    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(RecordsExtensionContributionReceipt::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $registry = new ContentGraphRegistry;

    $context = ExtensionContributionReceiptContext::forPackage($packageName, 'runtime', $registry::class);
    $receipts->withContext($context, function () use ($registry, $contributionClass): void {
        $registry->register($contributionClass);
        $registry->register(AuditUnlistedContentGraphExtractor::class);
    });

    $results = AuditExtensionContractsAction::run($directory, ['runtime']);

    expect($results)->toHaveCount(1)
        ->and($results[0])->toMatchArray([
            'package' => $packageName,
            'severity' => 'warning',
            'message' => 'Runtime contribution is not declared in the manifest.',
            'context' => [
                'status' => 'loaded-only',
                'contributionKey' => 'content-graph:Illuminate\\Database\\Eloquent\\Model:' . AuditUnlistedContentGraphExtractor::class,
                'expectedBucket' => 'runtime',
                'actualBucket' => 'runtime',
                'sourceClass' => AuditUnlistedContentGraphExtractor::class,
            ],
        ]);
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

it('does not report a declared scheduled job as missing because no runtime registry records scheduled-job receipts', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage('vendor/scheduled-job', RunsScheduledExtensionJob::class, [
        'type' => 'scheduled-job',
        'name' => 'vendor-package-prune',
        'command' => 'vendor:prune',
        'frequency' => 'daily',
    ]);

    expect(runtimeRegistrationAuditResults($directory, 'Declared contribution is not registered at runtime.', ['runtime']))->toBe([]);
});

it('does not report a declared page variation as missing because only a front-end render records its receipt', function (): void {
    app()->instance(ExtensionContributionReceiptRegistry::class, new ExtensionContributionReceiptRegistry);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage('vendor/page-variation', RegistersExtensionPageType::class, [
        'type' => 'page-variation',
        'name' => 'article',
        'modelClass' => stdClass::class,
        'resourceName' => 'article',
    ]);

    expect(runtimeRegistrationAuditResults($directory, 'Declared contribution is not registered at runtime.', ['runtime']))->toBe([]);
});

it('runs tagged receipt preparers before reconciling lazily recorded receipts', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage('vendor/lazy-receipt', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'vendor-package.declared']);

    $preparer = new class($receipts) implements PreparesExtensionContributionReceipts
    {
        public int $calls = 0;

        public function __construct(private readonly ExtensionContributionReceiptRegistry $receipts) {}

        public function prepareContributionReceipts(): void
        {
            if (++$this->calls > 1) {
                return;
            }

            recordTestReceipt($this->receipts, new ExtensionContributionReceiptData('vendor/lazy-receipt', 'runtime', ExtensionContributionType::OutboundEvent, 'vendor-package.lazy', OutboundEventDefinitionData::class, 'Vendor\\Provider'));
        }
    };
    app()->instance('test.lazy-receipt-preparer', $preparer);
    app()->tag(['test.lazy-receipt-preparer'], PreparesExtensionContributionReceipts::TAG);

    // The lazily recorded receipt is only visible to the audit once the preparer has run.
    $results = runtimeRegistrationAuditResults($directory, 'Runtime contribution is not declared in the manifest.', ['runtime']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['context']['contributionKey'] ?? null)->toBe('vendor-package.lazy')
        ->and($preparer->calls)->toBe(1);
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
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('capell-app/core', 'runtime', ExtensionContributionType::Model, 'core.builtin', stdClass::class, CapellServiceProvider::class, true));
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

it('reconciles runtime receipts declared through manifest contribution traceability', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/traced', 'runtime', ExtensionContributionType::RenderHook, 'vendor-traced-hook', 'Vendor\\Traced\\Hook', 'Vendor\\Provider'));
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/traced', 'runtime', ExtensionContributionType::RenderHook, 'vendor-untraced-hook', 'Vendor\\Traced\\Other', 'Vendor\\Provider'));
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/traced', 'admin', ExtensionContributionType::AdminResource, 'resource:Traced:default', 'Vendor\\Traced\\Resource', 'Vendor\\Provider'));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage('vendor/traced', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'vendor-traced.event']);
    $manifest = json_decode((string) file_get_contents($directory . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['contributionTraceability'] = ['contributions' => [
        ['type' => 'render-hook', 'key' => 'vendor-traced-hook', 'providerBucket' => 'runtime'],
        ['type' => 'admin-resource', 'key' => 'resource:Traced:default', 'providerBucket' => 'runtime'],
        ['type' => 'render-hook', 'key' => 'vendor-missing-hook', 'providerBucket' => 'runtime'],
    ]];
    file_put_contents($directory . '/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    $loadedOnly = runtimeRegistrationAuditResults($directory, 'Runtime contribution is not declared in the manifest.', ['runtime', 'admin']);
    expect($loadedOnly)->toHaveCount(1)
        ->and($loadedOnly[0]['context']['contributionKey'])->toBe('vendor-untraced-hook');

    $wrongBucket = runtimeRegistrationAuditResults($directory, 'Runtime contribution is registered in the wrong provider bucket.', ['runtime', 'admin']);
    expect($wrongBucket)->toHaveCount(1)
        ->and($wrongBucket[0]['context'])->toMatchArray(['contributionKey' => 'resource:Traced:default', 'expectedBucket' => 'runtime', 'actualBucket' => 'admin']);

    $declaredOnly = runtimeRegistrationAuditResults($directory, 'Declared contribution is not registered at runtime.', ['runtime', 'admin']);
    expect($declaredOnly)->toHaveCount(1)
        ->and($declaredOnly[0]['context']['contributionKey'])->toBe('vendor-missing-hook');
});

it('accepts runtime receipts of a type the manifest defers, and still reports undeclared types', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/type-level', 'runtime', ExtensionContributionType::Model, 'model:Vendor\\TypeLevel\\Models\\Area', 'Vendor\\TypeLevel\\Models\\Area', 'Vendor\\Provider'));
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/type-level', 'runtime', ExtensionContributionType::Model, 'model:Vendor\\TypeLevel\\Models\\Grant', 'Vendor\\TypeLevel\\Models\\Grant', 'Vendor\\Provider'));
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/type-level', 'runtime', ExtensionContributionType::RenderHook, 'vendor-type-level-hook', 'Vendor\\TypeLevel\\Hook', 'Vendor\\Provider'));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage('vendor/type-level', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event', 'event' => 'vendor-type-level.event']);
    $manifest = json_decode((string) file_get_contents($directory . '/capell.json'), true, flags: JSON_THROW_ON_ERROR);
    $manifest['contributionTraceability'] = ['deferredContributions' => ['model']];
    file_put_contents($directory . '/capell.json', json_encode($manifest, JSON_THROW_ON_ERROR));

    $loadedOnly = runtimeRegistrationAuditResults($directory, 'Runtime contribution is not declared in the manifest.', ['runtime']);

    expect(array_column(array_column($loadedOnly, 'context'), 'contributionKey'))->toBe(['vendor-type-level-hook']);
});

it('accepts every runtime receipt of a type declared without keys', function (): void {
    $receipts = new ExtensionContributionReceiptRegistry;
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/unkeyed', 'runtime', ExtensionContributionType::OutboundEvent, 'vendor-unkeyed.first', OutboundEventDefinitionData::class, 'Vendor\\Provider'));
    recordTestReceipt($receipts, new ExtensionContributionReceiptData('vendor/unkeyed', 'runtime', ExtensionContributionType::OutboundEvent, 'vendor-unkeyed.second', OutboundEventDefinitionData::class, 'Vendor\\Provider'));
    app()->instance(ExtensionContributionReceiptRegistry::class, $receipts);
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage('vendor/unkeyed', RegistersExtensionOutboundEvent::class, ['type' => 'outbound-event']);

    expect(runtimeRegistrationAuditResults($directory, 'Runtime contribution is not declared in the manifest.', ['runtime']))->toBe([]);
});
