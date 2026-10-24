<?php

declare(strict_types=1);

use Capell\Admin\Actions\Media\ResolveAdminUploadOriginalPathsAction;
use Capell\Admin\Support\Media\AdminUploadOwnership;
use Capell\Admin\Tests\Fixtures\Livewire;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DatabaseSchema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('preserves grants across overlapping session snapshots and read-only requests', function (string $driver, bool $sameForm): void {
    $cachePath = sys_get_temp_dir() . '/capell-upload-' . bin2hex(random_bytes(8));
    config()->set('cache.stores.upload-test', match ($driver) {
        'file' => ['driver' => 'file', 'path' => $cachePath],
        'database' => ['driver' => 'database', 'connection' => config('database.default'), 'table' => 'upload_grants_test'],
        default => ['driver' => 'array'],
    });
    if ($driver === 'database') {
        DatabaseSchema::connection(config('database.default'))->create('upload_grants_test', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
            $table->integer('expiration');
        });
    }

    Cache::setDefaultDriver('upload-test');
    $originalSession = resolve(SessionManager::class);
    $originalDriver = config('session.driver');
    $manager = new class(app()) extends SessionManager
    {
        public function useStore(Store $store): void
        {
            $this->drivers['overlap'] = $store;
            $this->setDefaultDriver('overlap');
        }
    };
    app()->instance('session', $manager);
    $handler = new ArraySessionHandler(120);
    $seed = new Store('upload-test', $handler);
    $seed->start();
    $seed->save();

    $requests = [];
    foreach (['a', 'b', 'reader'] as $tab) {
        $request = new Store('upload-test', $handler, $seed->getId());
        $request->start();
        $requests[$tab] = $request;
    }

    $ownership = resolve(AdminUploadOwnership::class);
    $makeField = function (string $tab) use ($sameForm): FileUpload {
        $livewire = Livewire::make()->data(['file' => ['stored' => 'docs/' . $tab . '.txt']]);
        $livewire->setId($sameForm ? 'same-form' : 'tab-' . $tab);

        $field = FileUpload::make('file')->disk('local')->directory('docs');
        Schema::make($livewire)->statePath('data')->components([$field])->getFlatComponents();

        return $field;
    };
    $a = $makeField('a');
    $b = $makeField('b');
    Storage::disk('local')->put('docs/a.txt', 'A');
    Storage::disk('local')->put('docs/b.txt', 'B');
    try {
        $manager->useStore($requests['a']);
        $ownership->remember($a, ['docs/a.txt']);
        $requests['a']->save();
        $manager->useStore($requests['b']);
        $ownership->remember($b, ['docs/b.txt']);
        $requests['b']->save();
        $manager->useStore($requests['reader']);
        $before = $requests['reader']->all();
        expect($ownership->paths($a))->toBe(['docs/a.txt']);
        expect($requests['reader']->all())->toBe($before);
        $requests['reader']->save();

        $retry = new Store('upload-test', $handler, $seed->getId());
        $retry->start();
        $manager->useStore($retry);
        expect($ownership->paths($a))->toBe(['docs/a.txt'])
            ->and($ownership->paths($b))->toBe(['docs/b.txt']);

        // Reading and retrying cannot extend a grant's lifetime.
        $this->travel(1)->minutes();
        expect($ownership->paths($a))->toBe(['docs/a.txt']);
        $this->travel((int) config('session.lifetime') - 1)->minutes();
        expect($ownership->paths($a))->toBe([])->and($ownership->paths($b))->toBe([]);
    } finally {
        app()->instance('session', $originalSession);
        config()->set('session.driver', $originalDriver);
        if ($driver === 'file') {
            resolve(Filesystem::class)->deleteDirectory($cachePath);
        }

        if ($driver === 'database') {
            DatabaseSchema::connection(config('database.default'))->drop('upload_grants_test');
        }
    }
})->with(['array', 'file', 'database'])->with([false, true]);

it('does not revive a deleted grant when its path is recreated', function (): void {
    $livewire = Livewire::make()->data(['file' => ['stored' => 'docs/a.txt']]);
    $livewire->setId('delete-form');

    $field = FileUpload::make('file')->disk('local');
    Schema::make($livewire)->statePath('data')->components([$field])->getFlatComponents();
    Storage::disk('local')->put('docs/a.txt', 'Owned upload');
    $ownership = resolve(AdminUploadOwnership::class);
    $ownership->remember($field, ['docs/a.txt']);

    expect(ResolveAdminUploadOriginalPathsAction::run($field))->toBe(['docs/a.txt']);
    Storage::disk('local')->delete('docs/a.txt');
    expect(ResolveAdminUploadOriginalPathsAction::run($field))->toBe([]);
    Storage::disk('local')->put('docs/a.txt', 'Replacement');
    expect(ResolveAdminUploadOriginalPathsAction::run($field))->toBe([]);
});

it('revokes grants on login session regeneration even for the same actor', function (): void {
    $livewire = Livewire::make()->data(['file' => ['stored' => 'docs/a.txt']]);
    $field = FileUpload::make('file')->disk('local');
    Schema::make($livewire)->statePath('data')->components([$field])->getFlatComponents();
    Storage::disk('local')->put('docs/a.txt', 'Owned upload');
    $ownership = resolve(AdminUploadOwnership::class);
    $ownership->remember($field, ['docs/a.txt']);

    session()->migrate(true);
    expect($ownership->paths($field))->toBe([]);
});

it('allocates only one bounded registry row per issued grant and never writes during paths', function (): void {
    $livewire = Livewire::make()->data(['file' => ['stored' => 'docs/a.txt']]);
    $field = FileUpload::make('file')->disk('local');
    Schema::make($livewire)->statePath('data')->components([$field])->getFlatComponents();
    Storage::disk('local')->put('docs/a.txt', 'Owned upload');
    $rows = fn (): array => DB::table('admin_upload_grants')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    $ownership = resolve(AdminUploadOwnership::class);
    $ownership->remember($field, ['docs/a.txt', 'docs/a.txt']);

    expect($rows())->toHaveCount(1)
        ->and(strlen($rows()[0]['scope_hash']))->toBe(64)
        ->and(strlen($rows()[0]['path_hash']))->toBe(64);
    Storage::disk('local')->put('docs/b.txt', 'Second owned upload');
    $ownership->remember($field, ['docs/b.txt']);
    expect($rows())->toHaveCount(2);
    $livewire->data['file'] = ['a' => 'docs/a.txt', 'b' => 'docs/b.txt'];
    $issued = $rows();
    $this->travel(1)->minutes();
    expect($ownership->paths($field))->toBe(['docs/a.txt', 'docs/b.txt']);
    expect($rows())->toBe($issued);

    $ownership->remember($field, ['docs/a.txt']);
    expect($rows())->toBe($issued);

    $livewire->data['file'] = array_map(fn (int $index): string => 'docs/forged-' . $index, range(1, 100));
    expect($ownership->paths($field))->toBe([]);
    expect($rows())->toBe($issued);

    $livewire->data['file'] = ['a' => 'docs/a.txt', 'b' => 'docs/b.txt'];
    Storage::disk('local')->delete('docs/a.txt');
    expect($ownership->paths($field))->toBe(['docs/b.txt']);
    expect($rows())->toBe($issued);

    $ownership->forgetMissingPaths($field);
    expect($rows())->toHaveCount(1);
    expect($ownership->paths($field))->toBe(['docs/b.txt']);
    Storage::disk('local')->delete('docs/b.txt');
    $ownership->forgetMissingPaths($field);
    expect($rows())->toBe([]);
});

it('retains failed-save grants in a fresh application with a fresh array cache', function (): void {
    config()->set('cache.default', 'array');
    $makeField = function (): FileUpload {
        $livewire = Livewire::make()->data(['file' => ['stored' => 'docs/fresh.txt']]);
        $livewire->setId('fresh-app-form');

        $field = FileUpload::make('file')->disk('local');
        Schema::make($livewire)->statePath('data')->components([$field])->getFlatComponents();

        return $field;
    };
    Storage::disk('local')->put('docs/fresh.txt', 'Stored before the failed save');
    $ownership = resolve(AdminUploadOwnership::class);
    $ownership->remember($makeField(), ['docs/fresh.txt']);

    expect($ownership->paths($makeField()))->toBe(['docs/fresh.txt']);
    $application = app();
    $cache = Cache::store('array');
    $databaseConfig = config('database');
    $diskConfig = array_replace(config('filesystems.disks.local'), ['root' => Storage::disk('local')->path('')]);
    // Keep the database alive outside either app, as a database server would be.
    $pdo = DB::connection()->getPdo();
    $sessionId = session()->getId();
    $token = session()->token();

    $this->refreshApplication();
    config()->set('database', $databaseConfig);
    DB::purge();
    DB::connection()->setPdo($pdo);
    config()->set('cache.default', 'array');
    config()->set('filesystems.disks.local', $diskConfig);

    session()->setId($sessionId);
    session()->start();
    session()->put('_token', $token);

    expect(app())->not->toBe($application)
        ->and(Cache::store('array'))->not->toBe($cache)
        ->and(resolve(AdminUploadOwnership::class)->paths($makeField()))->toBe(['docs/fresh.txt']);
});

it('physically prunes a bounded batch of expired abandoned scopes when granting', function (): void {
    $expired = array_map(fn (int $id): array => [
        'scope_hash' => hash('sha256', 'abandoned-' . $id),
        'path_hash' => hash('sha256', 'abandoned.txt'),
        'path' => 'abandoned.txt',
        'expires_at' => now()->subMinute(),
    ], range(1, 105));
    DB::table('admin_upload_grants')->insert($expired);
    $livewire = Livewire::make()->data(['file' => ['stored' => 'docs/current.txt']]);
    $field = FileUpload::make('file')->disk('local');
    Schema::make($livewire)->components([$field])->getFlatComponents();

    resolve(AdminUploadOwnership::class)->remember($field, ['docs/current.txt']);

    expect(DB::table('admin_upload_grants')->where('expires_at', '<=', now())->count())->toBe(5)
        ->and(DB::table('admin_upload_grants')->where('expires_at', '>', now())->pluck('path')->all())->toBe(['docs/current.txt']);
});
