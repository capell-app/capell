<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\UserModelPatch;
use Illuminate\Support\Facades\File;

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
            ->and($contents)->toContain('use Spatie\Activitylog\LogOptions;')
            ->and($contents)->toContain('use Spatie\Activitylog\Models\Activity;')
            ->and($contents)->toContain('use Spatie\Permission\Traits\HasRoles;')
            ->and($contents)->toContain('use Capell\Core\Models\Concerns\HasSitePermissions;')
            ->and($contents)->not->toContain('use Spatie\ActivityLog\LogOptions;')
            ->and($contents)->not->toContain('use Spatie\ActivityLog\Models\Activity;')
            ->and($contents)->not->toContain('Capell\Admin\Traits')
            ->and($contents)->toContain('use Notifiable, HasImpersonation, HasPanelShield, HasRoles, HasSitePermissions, LogsActivity;')
            ->and($contents)->not->toContain('LoginAuditgable')
            ->and($contents)->not->toContain('Capell\Admin\Models\Concerns\HasImpersonation, BezhanSalleh\FilamentShield\Traits\HasPanelShield')
            ->and($contents)->toContain('public function getActivitylogOptions(): LogOptions');
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
            ->and($contents)->toContain('use Spatie\Activitylog\Traits\LogsActivity;')
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
use Spatie\Activitylog\LogOptions;
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
            ->and($contents)->toContain('use Spatie\Activitylog\Traits\LogsActivity;')
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
    writeSetupUserModelForPatchTest(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Models;

use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Admin\Models\Concerns\HasImpersonation;
use Capell\Core\Models\Concerns\HasSitePermissions;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
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

    expect(function () use ($patch): void {
        $patch->apply();
    })
        ->toThrow(RuntimeException::class, 'Cannot apply patch when status is: already_applied');
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
