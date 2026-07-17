<?php

declare(strict_types=1);

use Capell\Frontend\Actions\ResolveFrontendDependencyPlanAction;
use Capell\Frontend\Data\Assets\FrontendDependencyPlanData;
use Capell\Frontend\Data\Assets\FrontendPackageDependencyData;
use Capell\Frontend\Enums\FrontendPackageDependencyType;
use Capell\Frontend\Enums\FrontendPackageManager;
use Capell\Frontend\Exceptions\FrontendResourcePlanException;
use Capell\Frontend\Support\Assets\FrontendPackageDependencyRegistry;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    File::deleteDirectory(sys_get_temp_dir() . '/capell-dependency-plan-test');
});

it('selects each supported manager and separates deterministic runtime and development commands', function (FrontendPackageManager $manager, string $lockfile, array $runtimePrefix, array $developmentPrefix): void {
    $path = dependencyPlanPath();
    File::put($path . '/package.json', json_encode(['packageManager' => $manager->value . '@1.0.0'], JSON_THROW_ON_ERROR));
    File::put($path . '/' . $lockfile, 'lock');
    $registry = new FrontendPackageDependencyRegistry;
    $registry->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/gallery'));
    $registry->register(new FrontendPackageDependencyData('vite-plugin-example', '^2.0.0', FrontendPackageDependencyType::Development, 'capell-app/gallery'));

    $plan = runBoundAction(ResolveFrontendDependencyPlanAction::class, new ResolveFrontendDependencyPlanAction($registry), $path);

    expect($plan->manager)->toBe($manager)
        ->and($plan->runtimeCommand)->toBe([...$runtimePrefix, 'swiper@^12.0.0'])
        ->and($plan->developmentCommand)->toBe([...$developmentPrefix, 'vite-plugin-example@^2.0.0']);
})->with([
    'npm' => [FrontendPackageManager::Npm, 'package-lock.json', ['npm', 'install'], ['npm', 'install', '--save-dev']],
    'pnpm' => [FrontendPackageManager::Pnpm, 'pnpm-lock.yaml', ['pnpm', 'add'], ['pnpm', 'add', '--save-dev']],
    'yarn' => [FrontendPackageManager::Yarn, 'yarn.lock', ['yarn', 'add'], ['yarn', 'add', '--dev']],
    'bun' => [FrontendPackageManager::Bun, 'bun.lock', ['bun', 'add'], ['bun', 'add', '--dev']],
]);

it('rejects conflicting lockfiles and package manager disagreement', function (): void {
    $path = dependencyPlanPath();
    File::put($path . '/package.json', json_encode(['packageManager' => 'pnpm@9.0.0'], JSON_THROW_ON_ERROR));
    File::put($path . '/package-lock.json', '{}');

    expect(fn (): FrontendDependencyPlanData => runBoundAction(ResolveFrontendDependencyPlanAction::class, new ResolveFrontendDependencyPlanAction(new FrontendPackageDependencyRegistry), $path))
        ->toThrow(FrontendResourcePlanException::class, 'conflicts');

    File::put($path . '/pnpm-lock.yaml', 'lock');

    expect(fn (): FrontendDependencyPlanData => runBoundAction(ResolveFrontendDependencyPlanAction::class, new ResolveFrontendDependencyPlanAction(new FrontendPackageDependencyRegistry), $path))
        ->toThrow(FrontendResourcePlanException::class, 'Conflicting');
});

it('merges identical requirements and requires resolution for divergent package constraints', function (): void {
    $path = dependencyPlanPath();
    File::put($path . '/package.json', '{}');
    $registry = new FrontendPackageDependencyRegistry;
    $registry->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/gallery'));
    $registry->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/slideshow'));

    $plan = runBoundAction(ResolveFrontendDependencyPlanAction::class, new ResolveFrontendDependencyPlanAction($registry), $path);
    expect($plan->runtimeCommand)->toBe(['npm', 'install', 'swiper@^12.0.0'])
        ->and($plan->requirements['swiper']['constraints'])->toHaveCount(2);

    $registry->register(new FrontendPackageDependencyData('swiper', '^11.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/legacy-gallery'));

    expect(fn (): FrontendDependencyPlanData => runBoundAction(ResolveFrontendDependencyPlanAction::class, new ResolveFrontendDependencyPlanAction($registry), $path))
        ->toThrow(FrontendResourcePlanException::class, 'require an application resolution');
});

it('uses an existing direct application dependency as the explicit resolution', function (): void {
    $path = dependencyPlanPath();
    File::put($path . '/package.json', json_encode(['dependencies' => ['swiper' => '^13.0.0']], JSON_THROW_ON_ERROR));
    $registry = new FrontendPackageDependencyRegistry;
    $registry->register(new FrontendPackageDependencyData('swiper', '^12.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/gallery'));
    $registry->register(new FrontendPackageDependencyData('swiper', '^11.0.0', FrontendPackageDependencyType::Runtime, 'capell-app/slideshow'));

    $plan = runBoundAction(ResolveFrontendDependencyPlanAction::class, new ResolveFrontendDependencyPlanAction($registry), $path);

    expect($plan->runtimeCommand)->toBe(['npm', 'install', 'swiper@^13.0.0'])
        ->and($plan->requirements['swiper']['resolution'])->toBe('application')
        ->and($plan->diagnostics)->toHaveCount(1);
});

function dependencyPlanPath(): string
{
    $path = sys_get_temp_dir() . '/capell-dependency-plan-test';
    File::deleteDirectory($path);
    File::ensureDirectoryExists($path);

    return $path;
}
