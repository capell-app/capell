<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\GenerateSitemapAction;
use Capell\Core\Data\UpgradeContext;
use Capell\Core\Facades\CapellCore;
use Capell\Core\Models\Page;
use Capell\Core\Support\Upgrade\EnsureMorphMapUpgradeStep;
use Capell\Core\Tests\Support\Fixtures\Autoload\InstallSupportActionReporter;
use Capell\Core\Tests\Support\Fixtures\Autoload\WhenBootedShimFallbackTestModel;
use Capell\Core\Tests\Support\Fixtures\Autoload\WhenBootedShimParentBase;
use Capell\Core\Tests\Support\Fixtures\Autoload\WhenBootedShimParentTestModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;

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

    $property = new ReflectionProperty(Model::class, 'bootedCallbacks');

    /** @var array<class-string, list<Closure>> $callbacks */
    $callbacks = $property->getValue();

    expect($callbacks)
        ->toHaveKey(Page::class)
        ->not->toHaveKey(Model::class);
});
