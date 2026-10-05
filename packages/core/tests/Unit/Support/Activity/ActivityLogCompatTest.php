<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Translation;
use Capell\Core\Support\Activity\ActivityLogCompat;
use Capell\Core\Support\Activity\LogOptions;
use Capell\Tests\Fixtures\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process;

it('resolves the installed options and logging trait for every logging model', function (): void {
    $class = ActivityLogCompat::logOptionsClass();

    foreach ([new Site, new Page, new Layout, new Translation, new User] as $model) {
        $options = $model->getActivitylogOptions();

        expect($options)->toBeInstanceOf($class)->toBeInstanceOf(LogOptions::class)
            ->and(class_uses_recursive($model))->toContain(ActivityLogCompat::logsActivityTrait())
            ->and($options->logOnlyDirty)->toBeTrue()
            ->and(array_intersect_key(get_object_vars($options), array_flip(['submitEmptyLogs', 'logEmptyChanges'])))->toContain(false);
    }
});

it('supports the renamed empty-change option by capability', function (): void {
    $options = new class
    {
        public bool $logEmptyChanges = true;

        public function dontLogEmptyChanges(): self
        {
            $this->logEmptyChanges = false;

            return $this;
        }
    };

    expect(ActivityLogCompat::withoutEmptyLogs($options))->toBe($options)
        ->and($options->logEmptyChanges)->toBeFalse();
});

it('reads legacy tracked values from properties', function (): void {
    $activity = new Activity(['properties' => [
        'old' => ['name' => null, 'enabled' => false],
        'attributes' => ['name' => 'Updated', 'enabled' => true],
        'workspace_id' => 42,
    ]]);

    expect(ActivityLogCompat::attributeValues($activity, 'old'))->toBe(['name' => null, 'enabled' => false])
        ->and(ActivityLogCompat::attributeValues($activity, 'attributes'))->toBe(['name' => 'Updated', 'enabled' => true]);
});

it('prefers modern tracked values and falls back for absent legacy values', function (mixed $changes, array $old, array $new): void {
    $activity = new class extends Model
    {
        use HasFactory;
    };
    $activity->setRawAttributes([
        'properties' => new Collection(['old' => ['name' => 'Legacy'], 'attributes' => ['name' => 'Legacy update']]),
        'attribute_changes' => $changes,
    ]);

    expect(ActivityLogCompat::attributeValues($activity, 'old'))->toBe($old)
        ->and(ActivityLogCompat::attributeValues($activity, 'attributes'))->toBe($new);
})->with([
    'array' => [['old' => ['name' => 'Modern'], 'attributes' => ['name' => 'Modern update']], ['name' => 'Modern'], ['name' => 'Modern update']],
    'collection' => [new Collection(['old' => ['name' => 'Modern'], 'attributes' => ['name' => 'Modern update']]), ['name' => 'Modern'], ['name' => 'Modern update']],
    'json' => ['{"old":{"name":"Modern"},"attributes":{"name":"Modern update"}}', ['name' => 'Modern'], ['name' => 'Modern update']],
    'historical row' => [null, ['name' => 'Legacy'], ['name' => 'Legacy update']],
    'empty column' => ['[]', ['name' => 'Legacy'], ['name' => 'Legacy update']],
    'partial changes' => [['attributes' => ['name' => 'Modern update']], ['name' => 'Legacy'], ['name' => 'Modern update']],
    'explicit empty values' => [['old' => [], 'attributes' => []], [], []],
    'malformed values' => [['old' => 'invalid', 'attributes' => 42], [], []],
]);

it('executes options and the legacy hook using the real installed vendor sources', function (): void {
    $root = dirname(__DIR__, 6);
    $process = new Process([PHP_BINARY, $root . '/packages/core/tests/fixtures/activitylog-options.php', $root]);
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'options' => ActivityLogCompat::logOptionsClass(),
        'trait' => ActivityLogCompat::logsActivityTrait(),
        'empty' => [class_exists('Spatie\\Activitylog\\Support\\LogOptions') ? 'logEmptyChanges' : 'submitEmptyLogs' => false],
        'configured' => true,
        'relation' => 'activity_log',
        'hook' => 'updated',
    ]);
});
