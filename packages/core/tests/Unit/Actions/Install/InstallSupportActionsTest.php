<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\GenerateSitemapAction;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Support\Process\ProcessFactoryInterface;
use Capell\Core\Support\Upgrade\EnsureMorphMapUpgradeStep;
use Capell\Core\Tests\Support\Fixtures\Autoload\InstallSupportActionReporter;
use Capell\Core\Tests\Support\Fixtures\Autoload\WhenBootedShimFallbackTestModel;
use Capell\Core\Tests\Support\Fixtures\Autoload\WhenBootedShimParentBase;
use Capell\Core\Tests\Support\Fixtures\Autoload\WhenBootedShimParentTestModel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('reports and delegates sitemap generation to artisan', function (): void {
    Artisan::command('capell:xml-sitemap', fn (): int => 0);

    $reporter = new InstallSupportActionReporter;

    GenerateSitemapAction::run($reporter);

    expect($reporter->lines)->toBe([
        ['step', 'Generating XML sitemaps…'],
        ['report', '✓ Sitemaps generated'],
    ]);
});

it('completes the install step with a clear message when no package provides the sitemap command', function (): void {
    // The command is not in this application, and the fresh-process probe lists no sitemap command either.
    $probe = Mockery::mock(Process::class);
    $probe->shouldReceive('setTimeout')->once();
    $probe->shouldReceive('run')->once();
    $probe->shouldReceive('isSuccessful')->andReturnTrue();
    $probe->shouldReceive('getOutput')->andReturn(json_encode([
        'commands' => [['name' => 'list']],
        'namespaces' => [['id' => '_global', 'commands' => ['list']]],
    ], JSON_THROW_ON_ERROR));
    $processes = Mockery::mock(ProcessFactoryInterface::class);
    $processes->shouldReceive('make')->once()->andReturn($probe);
    app()->instance(ProcessFactoryInterface::class, $processes);

    $reporter = new InstallSupportActionReporter;

    GenerateSitemapAction::run($reporter);

    expect($reporter->lines)->toBe([
        ['step', 'Generating XML sitemaps…'],
        ['report', __('capell-core::install.sitemap.skipped', ['command' => 'capell:xml-sitemap'])],
    ])->and($reporter->lines[1][1])->toContain('capell-app/site-discovery');
});

it('still fails the sitemap step when the sitemap command itself fails', function (): void {
    Artisan::command('capell:xml-sitemap', fn (): int => 1);

    expect(fn (): mixed => GenerateSitemapAction::run(new InstallSupportActionReporter))
        ->toThrow(RuntimeException::class, "Artisan command 'capell:xml-sitemap' failed with exit code 1.");
});

it('adds missing core models to the morph map without removing existing entries', function (): void {
    Relation::morphMap(['existing' => stdClass::class], false);
    CapellCore::shouldReceive('getModels')
        ->andReturn([
            'PageUrl' => 'App\\Models\\PageUrl',
            'SiteDomain' => 'App\\Models\\SiteDomain',
        ]);

    $step = new EnsureMorphMapUpgradeStep;
    $context = new UpgradeContext([], [], []);

    expect($step->id())->toBe('core.ensure-morph-map')
        ->and($step->label())->toBe('Verify all core models are present in the morph map')
        ->and($step->run($context))->toBeTrue()
        ->and(Relation::morphMap())->toMatchArray([
            'existing' => stdClass::class,
            'page_url' => 'App\\Models\\PageUrl',
            'site_domain' => 'App\\Models\\SiteDomain',
        ]);
});

it('runs boot callbacks immediately when the parent model has no whenBooted helper', function (): void {
    $called = false;

    WhenBootedShimFallbackTestModel::callWhenBooted(function () use (&$called): void {
        $called = true;
    });

    expect($called)->toBeTrue();
});

it('delegates boot callbacks to the parent helper when it exists', function (): void {
    WhenBootedShimParentTestModel::callWhenBooted(fn (): string => 'parent-called');

    expect(WhenBootedShimParentBase::$received)->toBe('parent-called');
});

it('registers page boot callbacks against the concrete model', function (): void {
    Page::clearBootedModels();

    Page::query()->getModel();

    $root = Page::factory()->createOne();
    $child = Page::factory()->createOne();
    $child->appendToNode($root)->save();

    expect($child->fresh()?->ancestors()->pluck('id')->all())->toContain($root->id)
        ->and($root->fresh()?->descendants()->pluck('id')->all())->toContain($child->id);
});
