<?php

declare(strict_types=1);

use Capell\Core\Actions\Install\EnsureDatabaseExistsAction;
use Capell\Core\Actions\Install\PrepareEnvironmentAction;
use Capell\Core\Tests\Support\Fixtures\Autoload\InstallSupportActionReporter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->environmentBase = app()->basePath();
    $this->environmentDatabase = app()->databasePath();
    $this->environmentDirectory = sys_get_temp_dir() . '/capell-environment-' . bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->environmentDirectory . '/database/migrations');
    File::ensureDirectoryExists($this->environmentDirectory . '/storage/app/public');
    File::ensureDirectoryExists($this->environmentDirectory . '/public');
    Artisan::all();
    app()->setBasePath($this->environmentDirectory);
    app()->useDatabasePath($this->environmentDirectory . '/database');
    config(['filesystems.links' => [$this->environmentDirectory . '/public/storage' => $this->environmentDirectory . '/storage/app/public']]);
    bindFakeAction(EnsureDatabaseExistsAction::class);
});

afterEach(function (): void {
    app()->setBasePath($this->environmentBase);
    app()->useDatabasePath($this->environmentDatabase);
    File::deleteDirectory($this->environmentDirectory);
});

it('makes public storage available', function (): void {
    File::put($this->environmentDirectory . '/storage/app/public/example.txt', 'public upload');
    $reporter = new InstallSupportActionReporter;

    PrepareEnvironmentAction::run($reporter);

    expect(File::get($this->environmentDirectory . '/public/storage/example.txt'))->toBe('public upload')
        ->and($reporter->lines)->toContain(['report', '✓ Storage linked']);
});

it('generates a notification migration when the table and migration are absent', function (): void {
    Schema::dropIfExists('notifications');
    // Testbench registers its own notifications migration path; an application without one has none registered.
    $migrator = resolve('migrator');
    app()->instance('migrator', new Migrator($migrator->getRepository(), resolve(ConnectionResolverInterface::class), resolve(Filesystem::class), resolve(Dispatcher::class)));
    $reporter = new InstallSupportActionReporter;

    PrepareEnvironmentAction::run($reporter);

    $migrations = glob(database_path('migrations/*create_notifications_table.php'));
    expect($migrations)->toBeArray()->toHaveCount(1)
        ->and($reporter->lines)->toContain(['report', '✓ Notifications table created']);
});

it('leaves existing notification tables without a duplicate migration', function (): void {
    Schema::dropIfExists('notifications');
    Schema::create('notifications', fn (Blueprint $table) => $table->uuid('id')->primary());
    $reporter = new InstallSupportActionReporter;

    PrepareEnvironmentAction::run($reporter);

    expect(glob(database_path('migrations/*create_notifications_table.php')))->toBe([])
        ->and(Schema::hasTable('notifications'))->toBeTrue()
        ->and($reporter->lines)->not->toContain(['report', '✓ Notifications table created']);
});

it('preserves an existing notification migration without generating a duplicate', function (): void {
    Schema::dropIfExists('notifications');
    $path = database_path('migrations/2035_01_01_000000_create_notifications_table.php');
    File::put($path, '<?php return "existing migration";');
    $reporter = new InstallSupportActionReporter;

    PrepareEnvironmentAction::run($reporter);

    expect(glob(database_path('migrations/*create_notifications_table.php')))->toBe([$path])
        ->and(require $path)->toBe('existing migration')
        ->and($reporter->lines)->not->toContain(['report', '✓ Notifications table created']);
});

it('does not generate a notification migration when an unconventionally named migration already creates the table', function (): void {
    Schema::dropIfExists('notifications');
    $path = database_path('migrations/2035_01_01_000000_add_notification_storage.php');
    File::put($path, <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration {
            public function up(): void
            {
                Schema::create("notifications", function (Blueprint $table): void {
                    $table->uuid('id')->primary();
                });
            }
        };
        PHP);
    $reporter = new InstallSupportActionReporter;

    PrepareEnvironmentAction::run($reporter);

    expect(glob(database_path('migrations/*create_notifications_table.php')))->toBe([])
        ->and($reporter->lines)->not->toContain(['report', '✓ Notifications table created']);
});
