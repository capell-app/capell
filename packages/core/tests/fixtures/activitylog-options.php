<?php

declare(strict_types=1);

use Capell\Core\Support\Activity\ActivityLogCompat;
use Capell\Core\Support\Activity\LogOptions;
use Capell\Core\Support\Activity\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

require __DIR__ . '/activitylog-bootstrap.php';

$root = $argv[1];
bootActivityLogFixture($root);
$model = new class extends Model
{
    use HasFactory;
    use LogsActivity;

    public ?string $hook = null;

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogCompat::options('test', ['updated_at']);
    }

    public function tapActivity(Activity $activity, string $event): void
    {
        $this->hook = $event;
    }
};
$model->beforeActivityLogged(new Activity, 'updated');
$options = $model->getActivitylogOptions();

echo json_encode([
    'options' => $options::class,
    'trait' => ActivityLogCompat::logsActivityTrait(),
    'empty' => array_intersect_key(get_object_vars($options), array_flip(['submitEmptyLogs', 'logEmptyChanges'])),
    'configured' => $options->logName === 'test' && $options->logOnlyDirty && $options->logAttributes === ['*'] && $options->logExceptAttributes === ['updated_at'],
    'relation' => $model->activities()->getRelated()->getTable(),
    'hook' => $model->hook,
], JSON_THROW_ON_ERROR);
