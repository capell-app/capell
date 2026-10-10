<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Activity\ActivityLogCompat;
use Spatie\Activitylog\Models\Activity;

it('records dirty model attributes and suppresses empty changes', function (string $modelClass, string $field): void {
    $model = $modelClass === Translation::class
        ? Translation::factory()->translatable(Site::factory()->createOne())->createOne([$field => 'Before'])
        : $modelClass::factory()->createOne([$field => 'Before']);
    // Hydrate database defaults before comparing dirty attributes on the factory instance.
    $model->refresh();
    $model->update([$field => 'After']);

    $activity = Activity::query()->forSubject($model)->where('event', 'updated')->sole();

    expect(ActivityLogCompat::attributeValues($activity, 'old'))->toHaveKey($field, 'Before')
        ->and(ActivityLogCompat::attributeValues($activity, 'attributes'))->toHaveKey($field, 'After')
        ->and($model->activities()->whereKey($activity->getKey())->exists())->toBeTrue();

    $this->travel(2)->seconds();
    $model->touch();

    expect(Activity::query()->forSubject($model)->where('event', 'updated')->count())->toBe(1);
})->with([
    'site' => [Site::class, 'name'],
    'layout' => [Layout::class, 'name'],
    'page' => [Page::class, 'name'],
    'translation' => [Translation::class, 'title'],
]);
