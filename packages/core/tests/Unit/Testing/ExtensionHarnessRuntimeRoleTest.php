<?php

declare(strict_types=1);

use Capell\Core\Data\Runtime\RuntimeRoleSelectionData;
use Capell\Core\Enums\RuntimeRole;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Support\ContentGraph\ContentGraphRegistry;
use Capell\Core\Support\Extensions\ExtensionContributionReceiptRegistry;
use Capell\Core\Support\Packages\InstalledRuntimeLifecycle;
use Capell\Core\Support\Runtime\RuntimeRoleResolver;
use Capell\Core\Testing\ExtensionTestHarness;
use Capell\Core\Tests\Support\Fixtures\Autoload\TestPageContentGraphExtractor;
use Capell\Core\Tests\Support\RuntimeReadiness\OrdinaryDeferredReceiptProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Vendor\ExtensionConformance\RuntimeReadiness\HarnessAdminProvider;
use Vendor\ExtensionConformance\RuntimeReadiness\HarnessFrontendProvider;
use Vendor\ExtensionConformance\RuntimeReadiness\HarnessRuntimeProvider;

it('activates actual harness contributions in the selected role and restores the host role', function (RuntimeRole $role, array $expected): void {
    $resolver = new RuntimeRoleResolver(new RuntimeRoleSelectionData(RuntimeRole::Combined, 'combined', true));
    app()->instance(RuntimeRoleResolver::class, $resolver);
    $directory = sys_get_temp_dir() . '/capell-harness-runtime-role-' . bin2hex(random_bytes(6));
    mkdir($directory, 0755, true);
    file_put_contents($directory . '/composer.json', json_encode([
        'name' => 'test/harness-runtime-readiness',
        'autoload' => ['psr-4' => ['Vendor\\ExtensionConformance\\RuntimeReadiness\\' => dirname(__DIR__, 2) . '/Support/ExtensionConformance/RuntimeReadiness']],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/capell.json', json_encode(capellManifestV3Array(
        name: 'test/harness-runtime-readiness',
        surfaces: ['admin', 'frontend'],
        namespace: 'Vendor\\ExtensionConformance\\RuntimeReadiness',
        providers: [
            'runtime' => [HarnessRuntimeProvider::class],
            'admin' => [HarnessAdminProvider::class],
            'frontend' => [HarnessFrontendProvider::class],
        ],
    ), JSON_THROW_ON_ERROR));

    $loaded = ExtensionTestHarness::forPath($directory)->bootProviders(app(), $role);
    $receipts = resolve(ExtensionContributionReceiptRegistry::class);
    expect(array_column($receipts->forPackage('test/harness-runtime-readiness'), 'key'))->toBe($expected)
        ->and(in_array(HarnessAdminProvider::class, $loaded, true))->toBe($role === RuntimeRole::Combined)
        ->and(resolve(RuntimeRoleResolver::class))->toBe($resolver)
        ->and(collect(resolve(ContentGraphRegistry::class)->forModel(Page::class))->whereInstanceOf(TestPageContentGraphExtractor::class))->toHaveCount(1)
        ->and(resolve(Router::class)->getRoutes()->match(Request::create('/test-harness-readiness-frontend'))->getName())->toBe('test.harness-readiness.frontend')
        ->and(collect(resolve(Router::class)->getRoutes()->getRoutes())->filter(static fn (Route $route): bool => $route->getName() === 'test.harness-readiness.admin'))->toHaveCount($role === RuntimeRole::Combined ? 1 : 0);

    CapellCore::registerPackage('test/host-role-restored');
    CapellCore::forcePackageInstalled('test/host-role-restored');
    $activated = false;
    $runtime = resolve(InstalledRuntimeLifecycle::class);
    $runtime->register(OrdinaryDeferredReceiptProvider::class, 'test/host-role-restored', 'admin', function () use (&$activated): void {
        $activated = true;
    });
    $runtime->refresh();

    expect($activated)->toBeTrue();
})->with([
    'public' => [RuntimeRole::Public, ['runtime', 'content-graph:' . Page::class . ':' . TestPageContentGraphExtractor::class, 'frontend']],
    'combined' => [RuntimeRole::Combined, ['runtime', 'content-graph:' . Page::class . ':' . TestPageContentGraphExtractor::class, 'admin', 'frontend']],
]);

it('restores nested lifecycle role scopes after an exception without losing pending registrations', function (): void {
    app()->instance(RuntimeRoleResolver::class, new RuntimeRoleResolver(new RuntimeRoleSelectionData(RuntimeRole::Combined, 'combined', true)));
    $runtime = resolve(InstalledRuntimeLifecycle::class);
    $calls = [];
    foreach (['nested', 'outer', 'after'] as $name) {
        CapellCore::registerPackage('test/role-' . $name);
        CapellCore::forcePackageInstalled('test/role-' . $name);
    }

    $register = function (string $name) use ($runtime, &$calls): void {
        $provider = match ($name) {
            'nested' => HarnessRuntimeProvider::class,
            'outer' => HarnessFrontendProvider::class,
            default => HarnessAdminProvider::class,
        };
        $runtime->register($provider, 'test/role-' . $name, 'admin', function () use ($name, &$calls): void {
            $calls[] = $name;
        });
    };
    $runtime->duringRuntimeRole(RuntimeRole::Public, function () use ($runtime, $register, &$calls): void {
        expect(fn () => $runtime->duringRuntimeRole(RuntimeRole::Combined, function () use ($runtime, $register): void {
            $register('nested');
            $runtime->refresh();
            throw new RuntimeException('scope failure');
        }))->toThrow(RuntimeException::class, 'scope failure');
        $register('outer');
        $runtime->refresh();
        expect($calls)->toBe(['nested']);
    });
    $register('after');
    $runtime->refresh();
    expect($calls)->toBe(['nested', 'outer', 'after']);
});
