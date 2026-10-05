<?php

declare(strict_types=1);

namespace {
    use Composer\Autoload\ClassLoader;

    $root = $argv[1];
    require $root . '/vendor/autoload.php';
    $loader = new ClassLoader;
    $loader->addPsr4('Capell\\Core\\', $root . '/packages/core/src');
    $loader->register(true);
}

namespace Spatie\Activitylog\Support {
    if (! class_exists(LogOptions::class)) {
        class LogOptions
        {
            public bool $logEmptyChanges = true;

            public bool $logOnlyDirty = false;

            public ?string $logName = null;

            /** @var list<string> */
            public array $logAttributes = [];

            /** @var list<string> */
            public array $logExceptAttributes = [];

            public static function defaults(): self
            {
                return new self;
            }

            public function useLogName(string $name): self
            {
                $this->logName = $name;

                return $this;
            }

            public function logAll(): self
            {
                $this->logAttributes = ['*'];

                return $this;
            }

            /** @param list<string> $attributes */
            public function logExcept(array $attributes): self
            {
                $this->logExceptAttributes = $attributes;

                return $this;
            }

            public function logOnlyDirty(): self
            {
                $this->logOnlyDirty = true;

                return $this;
            }

            public function dontLogEmptyChanges(): self
            {
                $this->logEmptyChanges = false;

                return $this;
            }
        }
    }
}

namespace Spatie\Activitylog\Models\Concerns {
    use Spatie\Activitylog\Support\LogOptions;

    if (! trait_exists(LogsActivity::class)) {
        trait LogsActivity
        {
            public function getActivitylogOptions(): LogOptions
            {
                return LogOptions::defaults();
            }
        }
    }
}

namespace {
    use Capell\Core\Support\Activity\ActivityLogCompat;
    use Capell\Core\Support\Activity\LogOptions;
    use Capell\Core\Support\Activity\LogsActivity;
    use Illuminate\Config\Repository;
    use Illuminate\Database\Capsule\Manager;
    use Illuminate\Database\Eloquent\Factories\HasFactory;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Foundation\Application;
    use Spatie\Activitylog\Models\Activity;

    $root = $argv[1];
    $app = new Application($root);
    $app->instance('config', new Repository([
        'activitylog' => ['activity_model' => Activity::class, 'table_name' => 'activity_log'],
    ]));
    $database = new Manager($app);
    $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
    $database->bootEloquent();
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
        'empty' => $options->logEmptyChanges,
        'configured' => $options->logName === 'test' && $options->logOnlyDirty && $options->logAttributes === ['*'] && $options->logExceptAttributes === ['updated_at'],
        'relation' => $model->activities()->getRelated()->getTable(),
        'hook' => $model->hook,
    ], JSON_THROW_ON_ERROR);
}
