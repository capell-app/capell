<?php

declare(strict_types=1);

use Capell\Admin\Data\Activity\ActivityResourceLinkData;
use Capell\Admin\Data\AdminSurfaceContributionData;
use Capell\Admin\Facades\CapellAdmin;
use Capell\Admin\Support\Activity\ActivityResourceLinkRegistry;
use Capell\Admin\Tests\Fixtures\Activity\ActivityResourceLinkRecord;
use Capell\Admin\Tests\Fixtures\Activity\ActivityResourceLinkRecordResource;
use Capell\Admin\Tests\Fixtures\Activity\AlternateActivityResourceLinkRecordResource;
use Capell\Admin\Tests\Fixtures\Activity\IndexOnlyActivityResourceLinkRecordResource;
use Capell\Admin\Tests\Fixtures\Activity\UnregisteredActivityResourceLinkRecord;
use Capell\Core\Models\Translation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    CapellAdmin::clearAdminSurfaceContributions();
    CapellAdmin::clearActivityResourceLinks();
});

it('resolves a direct registered model to its edit resource url', function (): void {
    registerActivityResourceLinkTestRoute(ActivityResourceLinkRecordResource::class, 'edit');
    registerActivityResourceLinkTestResource(ActivityResourceLinkRecordResource::class);

    $record = activityResourceLinkRecord(25, 'Linked record');

    $link = resolve(ActivityResourceLinkRegistry::class)->resolve($record);

    expect($link)->toBeInstanceOf(ActivityResourceLinkData::class);
    assert($link instanceof ActivityResourceLinkData);

    expect($link->record)->toBe($record)
        ->and($link->resourceClass)->toBe(ActivityResourceLinkRecordResource::class)
        ->and($link->url)->toContain('/admin/activity-link-records/25/edit')
        ->and($link->labelBasis)->toBe('Linked record')
        ->and($link->usedProxyRecord)->toBeFalse()
        ->and($link->usedIndexFallback)->toBeFalse();
});

it('returns null when no resource can be resolved for the subject', function (): void {
    $record = activityResourceLinkRecord(30, 'Unlinked record', UnregisteredActivityResourceLinkRecord::class);

    expect(resolve(ActivityResourceLinkRegistry::class)->resolve($record))->toBeNull();
});

it('falls back to a resource index url when the edit route is missing', function (): void {
    registerActivityResourceLinkTestRoute(IndexOnlyActivityResourceLinkRecordResource::class, 'index');
    registerActivityResourceLinkTestResource(IndexOnlyActivityResourceLinkRecordResource::class);

    $link = resolve(ActivityResourceLinkRegistry::class)->resolve(activityResourceLinkRecord(35));

    expect($link)->toBeInstanceOf(ActivityResourceLinkData::class);
    assert($link instanceof ActivityResourceLinkData);

    expect($link->resourceClass)->toBe(IndexOnlyActivityResourceLinkRecordResource::class)
        ->and($link->url)->toContain('/admin/index-only-activity-link-records')
        ->and($link->usedIndexFallback)->toBeTrue();
});

it('uses an explicit activity resource link declaration before automatic lookup', function (): void {
    registerActivityResourceLinkTestRoute(ActivityResourceLinkRecordResource::class, 'edit');
    registerActivityResourceLinkTestRoute(AlternateActivityResourceLinkRecordResource::class, 'edit');
    registerActivityResourceLinkTestResource(ActivityResourceLinkRecordResource::class);

    CapellAdmin::registerActivityResourceLink(
        subjectClass: ActivityResourceLinkRecord::class,
        resourceClass: AlternateActivityResourceLinkRecordResource::class,
    );

    $link = resolve(ActivityResourceLinkRegistry::class)->resolve(activityResourceLinkRecord(40));

    expect($link)->toBeInstanceOf(ActivityResourceLinkData::class);
    assert($link instanceof ActivityResourceLinkData);

    expect($link->resourceClass)->toBe(AlternateActivityResourceLinkRecordResource::class)
        ->and($link->url)->toContain('/admin/alternate-activity-link-records/40/edit');
});

it('resolves translation activity through its translatable model', function (): void {
    registerActivityResourceLinkTestRoute(ActivityResourceLinkRecordResource::class, 'edit');
    registerActivityResourceLinkTestResource(ActivityResourceLinkRecordResource::class);

    $record = activityResourceLinkRecord(45, 'Translated record');
    $translation = new Translation;
    $translation->setRelation('translatable', $record);

    $link = resolve(ActivityResourceLinkRegistry::class)->resolve($translation);

    expect($link)->toBeInstanceOf(ActivityResourceLinkData::class);
    assert($link instanceof ActivityResourceLinkData);

    expect($link->subject)->toBe($translation)
        ->and($link->record)->toBe($record)
        ->and($link->resourceClass)->toBe(ActivityResourceLinkRecordResource::class)
        ->and($link->url)->toContain('/admin/activity-link-records/45/edit')
        ->and($link->usedProxyRecord)->toBeTrue();
});

/**
 * @param  class-string<Filament\Resources\Resource>  $resourceClass
 */
function registerActivityResourceLinkTestRoute(string $resourceClass, string $page): void
{
    $routeName = $resourceClass::getRouteBaseName() . '.' . $page;
    $routePath = '/admin/' . $resourceClass::getSlug();

    if ($page === 'edit') {
        $routePath .= '/{record}/edit';
    }

    Route::name($routeName)->get($routePath, fn (): string => $page);
}

/**
 * @param  class-string<Filament\Resources\Resource>  $resourceClass
 */
function registerActivityResourceLinkTestResource(string $resourceClass): void
{
    CapellAdmin::contributeToAdminSurface(AdminSurfaceContributionData::resource(
        class: $resourceClass,
        group: 'ActivityResourceLinkRegistryTest',
    ));
}

/**
 * @template TRecord of Model
 *
 * @param  class-string<TRecord>  $recordClass
 * @return TRecord
 */
function activityResourceLinkRecord(
    int $id,
    string $name = 'Linked record',
    string $recordClass = ActivityResourceLinkRecord::class,
): Model {
    $record = new $recordClass;
    $record->forceFill([
        'id' => $id,
        'name' => $name,
    ]);
    $record->exists = true;

    return $record;
}
