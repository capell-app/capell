<?php

declare(strict_types=1);

use Capell\Core\Support\Activity\ActivityLogCompat;
use Capell\Core\Support\Activity\LogOptions;
use Capell\Core\Support\Activity\LogsActivity;
use Capell\Tests\Fixtures\Activity\ContractActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;
use Spatie\Activitylog\Models\Activity;

require __DIR__ . '/activitylog-bootstrap.php';

$root = $argv[1];
bootActivityLogFixture($root);

if (isset($argv[2])) {
    require $argv[2];
    $userClass = 'App\\Models\\User';
    throw_unless(class_exists($userClass, false), LogicException::class, 'The generated User class did not load.');

    Schema::create('activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('log_name')->nullable();
        $table->string('event')->nullable();
        $table->text('description');
        $table->nullableMorphs('subject');
        $table->nullableMorphs('causer');
        $table->json('properties')->nullable();
        $table->json('attribute_changes')->nullable();
        $table->uuid('batch_uuid')->nullable();
        $table->timestamps();
    });
    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });
    $instance = new $userClass;
    $options = $instance->getActivitylogOptions();
    $instance->forceFill(['name' => 'Before'])->save();
    $instance->forceFill(['name' => 'After'])->save();
    $updated = Activity::query()->where('event', 'updated')->sole();
    $user = new ReflectionClass($userClass);
    $relation = $user->hasMethod('activities') ? 'activities' : 'activitiesAsSubject';
    echo json_encode([
        'activities' => $user->hasMethod($relation),
        'options_class' => $options::class,
        'logged_name' => ActivityLogCompat::attributeValues($updated, 'attributes')['name'] ?? null,
        'relation_count' => $instance->{$relation}()->count(),
        'options' => $user->getMethod('getActivitylogOptions')->getReturnType()?->__toString(),
        'trait' => in_array(LogsActivity::class, class_uses_recursive($user->getName()), true),
        'audit_alias' => $user->hasMethod('enableAudit'),
        'host_hook' => ActivityLogCompat::properties($updated)['host_hook'] ?? null,
        'log_name' => $updated->log_name,
    ], JSON_THROW_ON_ERROR);

    return;
}

config()->set('activitylog.activity_model', ContractActivity::class);
Schema::create('custom_activity_log', function (Blueprint $table): void {
    $table->id();
    $table->string('log_name')->nullable();
    $table->string('event')->nullable();
    $table->text('description');
    $table->nullableMorphs('subject');
    $table->nullableMorphs('causer');
    $table->json('properties')->nullable();
    $table->uuid('batch_uuid')->nullable();
    $table->timestamps();
});
Schema::create('logged_subjects', function (Blueprint $table): void {
    $table->id();
    $table->string('name');
    $table->timestamps();
});
$migration = require $root . '/packages/core/database/migrations/2026_10_05_000001_add_attribute_changes_to_activity_log_table.php';
$migration->up();
$migration->up();

$subject = new class extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'logged_subjects';

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogCompat::options('fixture', ['created_at', 'updated_at']);
    }
};
$subject->beforeActivityLogged(new ContractActivity, 'created');
$subject->fill(['name' => 'Before'])->save();
$subject->update(['name' => 'After']);
$subject->touch();
$logged = ContractActivity::query()->where('event', 'updated')->sole();
$subjectWithHook = new class extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $table = 'logged_subjects';

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogCompat::options('fixture', ['created_at', 'updated_at']);
    }

    public function tapActivity(Model&ActivityContract $activity, string $event): void
    {
        $activity->properties = collect(['hook' => $event]);
    }
};
$subjectWithHook->fill(['name' => 'Hook'])->save();

echo json_encode([
    'source' => new ReflectionClass(Activity::class)->getFileName(),
    'core_source' => new ReflectionClass(ActivityLogCompat::class)->getFileName(),
    'model' => ActivityLogCompat::activityModelClass(),
    'column' => Schema::hasColumn('custom_activity_log', 'attribute_changes'),
    'old' => ActivityLogCompat::attributeValues($logged, 'old'),
    'new' => ActivityLogCompat::attributeValues($logged, 'attributes'),
    'relation_count' => $subject->activities()->count(),
    'hook' => ActivityLogCompat::properties(ContractActivity::query()->latest('id')->firstOrFail())['hook'],
], JSON_THROW_ON_ERROR);
