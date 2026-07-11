<?php

declare(strict_types=1);

use Capell\Admin\Filament\Components\Forms\Site\DomainsRepeater;
use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Capell\Core\Models\SiteDomain;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\View\View;

it('normalizes stored domain parts into an editable url', function (): void {
    $component = DomainsRepeater::make();

    expect(domainsRepeaterMutateBeforeFill($component, [
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/landing',
    ]))->toMatchArray([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/landing',
        'url' => 'https://example.test/landing',
    ]);
});

it('normalizes editable urls into relationship columns for create and save', function (): void {
    $component = DomainsRepeater::make();
    $record = SiteDomain::factory()->make();

    expect(domainsRepeaterMutateBeforeCreate($component, [
        'url' => 'https://example.test/path/',
        'language_id' => 1,
    ]))->toMatchArray([
        'scheme' => 'https',
        'domain' => 'example.test',
        'path' => '/path',
        'language_id' => 1,
        'default' => false,
        'status' => true,
    ]);

    expect(domainsRepeaterMutateBeforeSave($component, [
        'url' => 'http://example.test/',
        'language_id' => 1,
        'default' => true,
        'status' => false,
    ], $record))->toMatchArray([
        'scheme' => 'http',
        'domain' => 'example.test',
        'path' => null,
        'language_id' => 1,
        'default' => true,
        'status' => false,
    ]);
});

it('stores generated host-domain urls with a null domain', function (): void {
    $component = DomainsRepeater::make();

    expect(domainsRepeaterMutateBeforeCreate($component, [
        'url' => 'https://example.test/site',
        'language_id' => 1,
        'use_host_domain' => true,
    ]))->toMatchArray([
        'scheme' => 'https',
        'domain' => null,
        'path' => '/site',
        'language_id' => 1,
        'default' => false,
        'status' => true,
    ]);
});

it('renders existing domain labels from the loaded record language', function (): void {
    $english = Language::factory()->english()->create();
    $site = Site::factory()->withTranslations($english, siteDomainData: [
        'domain' => 'example.test',
        'language_id' => $english->getKey(),
    ])->create(['language_id' => $english->getKey()]);
    $site->load('siteDomains.language');

    $label = domainsRepeaterItemLabel(DomainsRepeater::make(), [
        'id' => $site->siteDomains->first()->getKey(),
        'language_id' => $english->getKey(),
        'url' => 'https://example.test',
    ], $site);

    expect($label)->toContain('https://example.test')
        ->and($label)->toContain('English');
});

/**
 * @param  array<string, mixed>  $state
 */
function domainsRepeaterItemLabel(DomainsRepeater $component, array $state, ?Site $record): string
{
    $callback = Closure::bind(fn (): mixed => $component->itemLabel, null, DomainsRepeater::class)();

    if (! $callback instanceof Closure) {
        return '';
    }

    $label = $component->evaluate($callback, [
        'uuid' => 'item-1',
        'state' => $state,
        'record' => $record,
    ]);

    return match (true) {
        $label instanceof View => $label->render(),
        $label instanceof Htmlable => $label->toHtml(),
        is_string($label) => $label,
        default => '',
    };
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function domainsRepeaterMutateBeforeFill(DomainsRepeater $component, array $data): array
{
    $mutatedData = $component->mutateRelationshipDataBeforeFill($data);

    expect($mutatedData)->toBeArray();

    return $mutatedData;
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function domainsRepeaterMutateBeforeCreate(DomainsRepeater $component, array $data): array
{
    $mutatedData = $component->mutateRelationshipDataBeforeCreate($data);

    expect($mutatedData)->toBeArray();

    /** @var array<string, mixed> $mutatedData */
    return $mutatedData;
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function domainsRepeaterMutateBeforeSave(DomainsRepeater $component, array $data, SiteDomain $record): array
{
    $mutatedData = $component->mutateRelationshipDataBeforeSave($data, $record);

    expect($mutatedData)->toBeArray();

    /** @var array<string, mixed> $mutatedData */
    return $mutatedData;
}
