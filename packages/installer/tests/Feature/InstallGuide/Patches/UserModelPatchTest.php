<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\UserModelPatch;
use Illuminate\Support\Facades\File;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-user-model-patch-test-' . uniqid();

    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);

    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

function writeSetupUserModelForPatchTest(string $content): string
{
    $path = base_path('app/Models/User.php');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, $content);

    return $path;
}

function cleanupSetupUserModelForPatchTest(): void
{
    $appPath = base_path('app');
    $backupPath = storage_path('capell/php-file-backups');

    if (is_dir($appPath)) {
        exec('rm -rf ' . escapeshellarg($appPath));
    }

    if (is_dir($backupPath)) {
        exec('rm -rf ' . escapeshellarg($backupPath));
    }
}

function loadPatchedUserModelForTest(string $path): array
{
    $root = dirname(__DIR__, 6);
    $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', $root . '/packages/core/tests/fixtures/activitylog-runtime.php', $root, $path]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('adopts existing vendor logging by resolved name and produces a loadable user', function (string $imports, string $trait, string $options): void {
    $path = writeSetupUserModelForPatchTest("<?php\ndeclare(strict_types=1);\nnamespace App\\Models;\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\n" . $imports . "\nclass User extends Authenticatable {\nuse " . $trait . ";\npublic function getActivitylogOptions(): " . $options . ' { return ' . $options . "::defaults()->logAll(); }\n}\n");
    $patch = new UserModelPatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);
    $patch->apply();

    expect(loadPatchedUserModelForTest($path))->toMatchArray(['activities' => true, 'trait' => true])
        ->and($patch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and(file_get_contents($path))->toContain('::defaults()->logAll()');
})->with([
    'v4 direct' => ['', LogsActivity::class, LogOptions::class],
    'v4 import' => ['use Spatie\\Activitylog\\Traits\\LogsActivity; use Spatie\\Activitylog\\LogOptions;', 'LogsActivity', 'LogOptions'],
    'v5 import' => ['use Spatie\\Activitylog\\Models\\Concerns\\LogsActivity; use Spatie\\Activitylog\\Support\\LogOptions;', 'LogsActivity', 'LogOptions'],
    'v5 aliases' => ['use Spatie\\Activitylog\\Models\\Concerns\\LogsActivity as Audit; use Spatie\\Activitylog\\Support\\LogOptions as AuditOptions;', 'Audit', 'AuditOptions'],
    'v5 grouped imports' => ['use Spatie\\Activitylog\\Models\\Concerns\\{LogsActivity as Audit}; use Spatie\\Activitylog\\Support\\{LogOptions as AuditOptions};', 'Audit', 'AuditOptions'],
    'v5 direct' => ['', '\\Spatie\\Activitylog\\Models\\Concerns\\LogsActivity', '\\Spatie\\Activitylog\\Support\\LogOptions'],
    'v5 namespace alias' => ['use Spatie\\Activitylog as Audit;', 'Audit\\Models\\Concerns\\LogsActivity', 'Audit\\Support\\LogOptions'],
    'unrelated imported short name' => ['use Illuminate\\Notifications\\Notifiable as LogsActivity;', 'LogsActivity', '\\Capell\\Core\\Support\\Activity\\LogOptions'],
    'unrelated local short name' => ['trait LogsActivity { public function localAudit(): bool { return true; } }', 'LogsActivity', '\\Capell\\Core\\Support\\Activity\\LogOptions'],
]);

it('can apply missing Capell admin role support to an existing Filament user model', function (): void {
    $path = writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
PHP);

    try {
        $patch = new UserModelPatch;

        expect($patch->probe())->toBe(PatchStatus::Applicable);

        $patch->apply();

        $contents = file_get_contents($path);

        expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
            ->and($contents)->toContain('use BezhanSalleh\FilamentShield\Traits\HasPanelShield;')
            ->and($contents)->toContain('use Capell\Admin\Models\Concerns\HasImpersonation;')
            ->and($contents)->toContain('use Capell\Core\Support\Activity\LogOptions;')
            ->and($contents)->toContain('use Capell\Core\Support\Activity\ActivityLogCompat;')
            ->and($contents)->toContain('use Spatie\Activitylog\Models\Activity;')
            ->and($contents)->toContain('use Spatie\Permission\Traits\HasRoles;')
            ->and($contents)->toContain('use Capell\Core\Models\Concerns\HasSitePermissions;')
            ->and($contents)->not->toContain('use Spatie\ActivityLog\LogOptions;')
            ->and($contents)->not->toContain('use Spatie\ActivityLog\Models\Activity;')
            ->and($contents)->not->toContain('Capell\Admin\Traits')
            ->and($contents)->toContain('use Notifiable, HasImpersonation, HasPanelShield, HasRoles, HasSitePermissions, LogsActivity;')
            ->and($contents)->not->toContain('LoginAuditgable')
            ->and($contents)->not->toContain('Capell\Admin\Models\Concerns\HasImpersonation, BezhanSalleh\FilamentShield\Traits\HasPanelShield')
            ->and($contents)->toContain('public function getActivitylogOptions(): LogOptions')
            ->and($contents)->toContain("return ActivityLogCompat::options('user',")
            ->and($contents)->not->toContain('dontSubmitEmptyLogs');
    } finally {
        cleanupSetupUserModelForPatchTest();
    }
});

it('adds only core admin traits to the user model', function (): void {
    $path = writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
PHP);

    try {
        $patch = new UserModelPatch;

        expect($patch->probe())->toBe(PatchStatus::Applicable);

        $patch->apply();

        $contents = file_get_contents($path);

        expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
            ->and($contents)->toContain('use BezhanSalleh\FilamentShield\Traits\HasPanelShield;')
            ->and($contents)->toContain('use Spatie\Permission\Traits\HasRoles;')
            ->and($contents)->toContain('use Capell\Core\Support\Activity\LogsActivity;')
            ->and($contents)->toContain('use Notifiable, HasImpersonation, HasPanelShield, HasRoles, HasSitePermissions, LogsActivity;')
            ->and($contents)->not->toContain('LoginAuditgable')
            ->and($contents)->not->toContain('Rappasoft\LaravelLoginAudit');
    } finally {
        cleanupSetupUserModelForPatchTest();
    }
});

it('can complete a partially prepared admin user model', function (): void {
    $path = writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Capell\Core\Support\Activity\LogOptions;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use Notifiable;
    use HasRoles;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
PHP);

    try {
        $patch = new UserModelPatch;

        expect($patch->probe())->toBe(PatchStatus::Applicable);

        $patch->apply();

        $contents = file_get_contents($path);

        expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
            ->and($contents)->toContain('use BezhanSalleh\FilamentShield\Traits\HasPanelShield;')
            ->and($contents)->toContain('use Capell\Admin\Models\Concerns\HasImpersonation;')
            ->and($contents)->toContain('use Capell\Core\Support\Activity\LogsActivity;')
            ->and($contents)->toContain('use Notifiable;')
            ->and($contents)->toContain('use HasRoles, HasImpersonation, HasPanelShield, HasSitePermissions, LogsActivity;')
            ->and(substr_count($contents, 'public function getActivitylogOptions(): LogOptions'))->toBe(1)
            ->and($contents)->toContain('return LogOptions::defaults();');
    } finally {
        cleanupSetupUserModelForPatchTest();
    }
});

it('treats customized or unparseable user models as manual install guide work', function (): void {
    $patch = new UserModelPatch;

    writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

class User extends CustomBaseUser
{
}
PHP);

    expect($patch->probe())->toBe(PatchStatus::Customised);

    writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

class Account
{
}
PHP);

    expect($patch->probe())->toBe(PatchStatus::Unsupported);

    writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

class User extends
PHP);

    expect($patch->probe())->toBe(PatchStatus::Unsupported);
});

it('does not apply over an already patched user model', function (): void {
    $path = writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Admin\Models\Concerns\HasImpersonation;
use Capell\Core\Models\Concerns\HasSitePermissions;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Capell\Core\Support\Activity\LogOptions;
use Capell\Core\Support\Activity\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasImpersonation, HasPanelShield, HasRoles, HasSitePermissions, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
PHP);

    $patch = new UserModelPatch;

    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied);
    expect(loadPatchedUserModelForTest($path))->toMatchArray(['activities' => true, 'trait' => true]);

    expect(function () use ($patch): void {
        $patch->apply();
    })
        ->toThrow(RuntimeException::class, 'Cannot apply patch when status is: already_applied');

    file_put_contents($path, str_replace('getActivitylogOptions(): LogOptions', 'getActivitylogOptions(): \\stdClass', file_get_contents($path)));

    expect($patch->probe())->toBe(PatchStatus::Customised);
});

it('does not report a colliding vendor and core trait as already applied', function (): void {
    $path = writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Admin\Models\Concerns\HasImpersonation;
use Capell\Core\Models\Concerns\HasSitePermissions;
use Capell\Core\Support\Activity\LogOptions;
use Capell\Core\Support\Activity\LogsActivity;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    use HasImpersonation, HasPanelShield, HasRoles, HasSitePermissions, LogsActivity;
    use \Spatie\Activitylog\Traits\LogsActivity {
        enableLogging as enableAudit;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults();
    }
}
PHP);
    $patch = new UserModelPatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);
    $patch->apply();

    expect(loadPatchedUserModelForTest($path))->toMatchArray(['activities' => true, 'trait' => true, 'audit_alias' => true])
        ->and($patch->probe())->toBe(PatchStatus::AlreadyApplied);
});

it('can patch a minimal user model without any existing trait use block', function (): void {
    $path = writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
}
PHP);

    $patch = new UserModelPatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);

    $patch->apply();

    expect(file_get_contents($path))
        ->toContain('implements FilamentUser')
        ->toContain('use HasImpersonation, HasPanelShield, HasRoles, HasSitePermissions, LogsActivity;')
        ->toContain('public function getActivitylogOptions(): LogOptions');
});
