<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Contracts\Extensions\RegistersExtensionBlueprintSubject;
use Capell\Core\Contracts\Extensions\RegistersExtensionOutboundEvent;
use Capell\Core\Data\OutboundEventDefinitionData;
use Capell\Core\Enums\BlueprintSubjectEnum;
use Capell\Core\Support\BlueprintSubjectRegistry;
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

        file_put_contents($directory . '/capell.json', json_encode(
            capellManifestV3Array(
                name: $packageName,
                namespace: rtrim($namespace, '\\'),
                providers: ['runtime' => [$namespace . 'Providers\\PackageServiceProvider']],
                overrides: ['contributes' => [[...$contribution, 'class' => $contributionClass]]],
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
    function runtimeRegistrationAuditResults(string $directory, string $message): array
    {
        return array_values(array_filter(
            AuditExtensionContractsAction::run($directory),
            static fn (array $result): bool => $result['message'] === $message,
        ));
    }
}

const OUTBOUND_EVENT_WARNING = 'Outbound event contribution is not registered at runtime.';
const BLUEPRINT_SUBJECT_WARNING = 'Blueprint subject contribution is not registered at runtime.';

it('warns when a declared outbound event is not registered at runtime', function (): void {
    app()->instance(OutboundEventRegistry::class, new OutboundEventRegistry);

    $directory = makeRuntimeRegistrationAuditPackage(
        'vendor/outbound-missing',
        RegistersExtensionOutboundEvent::class,
        ['type' => 'outbound-event', 'event' => 'vendor-package.thing-happened'],
    );

    $results = runtimeRegistrationAuditResults($directory, OUTBOUND_EVENT_WARNING);

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

    $results = runtimeRegistrationAuditResults($directory, BLUEPRINT_SUBJECT_WARNING);

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
