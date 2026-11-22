<?php

declare(strict_types=1);

use Capell\Core\Actions\Extensions\AuditExtensionContractsAction;
use Capell\Core\Contracts\Extensions\RegistersExtensionRoute;

/**
 * Write one package into $parent/$directoryName. $files maps a path under src/ to its PHP body.
 *
 * @param  array<string, string>  $files
 * @param  list<array<string, mixed>>  $contributions
 */
function writeSiblingAuditPackage(string $parent, string $directoryName, string $packageName, string $namespace, array $files, array $contributions): void
{
    $directory = $parent . '/' . $directoryName;

    foreach ($files as $relativePath => $body) {
        $path = $directory . '/src/' . $relativePath;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n" . $body);
    }

    file_put_contents($directory . '/composer.json', json_encode([
        'name' => $packageName,
        'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    file_put_contents($directory . '/capell.json', json_encode(
        capellManifestV3Array(
            name: $packageName,
            namespace: $namespace,
            providers: ['runtime' => [$namespace . '\\Providers\\PackageServiceProvider']],
            overrides: ['contributes' => $contributions],
        ),
        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
    ));
}

/**
 * A base package and an add-on whose contribution implements an interface the base defines,
 * the shape of shop and shop-paypal. The add-on's directory name extends the base's, so a
 * path-sorted walk reaches "acme-addon/capell.json" before "acme/capell.json" ('-' sorts
 * before '/').
 */
function makeSiblingAuditPackages(): string
{
    $parent = sys_get_temp_dir() . '/capell-sibling-audit-' . bin2hex(random_bytes(6));
    $provider = static fn (string $namespace): string => "namespace {$namespace}\\Providers;\n\nfinal class PackageServiceProvider extends \\Illuminate\\Support\\ServiceProvider\n{\n}\n";
    $baseNamespace = 'SiblingAudit' . bin2hex(random_bytes(4)) . '\\Acme';
    $addonNamespace = $baseNamespace . 'Addon';

    writeSiblingAuditPackage($parent, 'acme', 'vendor/acme', $baseNamespace, [
        'Providers/PackageServiceProvider.php' => $provider($baseNamespace),
        'Contracts/AcmeRouteContribution.php' => "namespace {$baseNamespace}\\Contracts;\n\ninterface AcmeRouteContribution extends \\" . RegistersExtensionRoute::class . "\n{\n}\n",
    ], []);

    writeSiblingAuditPackage($parent, 'acme-addon', 'vendor/acme-addon', $addonNamespace, [
        'Providers/PackageServiceProvider.php' => $provider($addonNamespace),
        'Contributions/AddonRoute.php' => "namespace {$addonNamespace}\\Contributions;\n\nfinal class AddonRoute implements \\{$baseNamespace}\\Contracts\\AcmeRouteContribution\n{\n    public static function compatibleCapellApiVersion(): string\n    {\n        return '^1.0';\n    }\n}\n",
    ], [['type' => 'route', 'class' => $addonNamespace . '\\Contributions\\AddonRoute', 'providerBucket' => 'runtime']]);

    return $parent;
}

it('loads a sibling base package before auditing an add-on whose directory sorts first', function (): void {
    $parent = makeSiblingAuditPackages();

    $errors = array_values(array_filter(
        AuditExtensionContractsAction::run($parent, ['runtime']),
        static fn (array $result): bool => $result['severity'] === 'error',
    ));

    expect($errors)->toBe([]);
});
