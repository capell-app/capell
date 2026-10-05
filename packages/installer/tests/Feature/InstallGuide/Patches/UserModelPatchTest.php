<?php

declare(strict_types=1);

use Capell\Core\Data\InstallInputData;
use Capell\Core\Support\Activity\ActivityLogCompat;
use Capell\Core\Support\Install\InstallPatchContext;
use Capell\Core\Support\Install\InstallPatchRegistry;
use Capell\Core\Support\Install\NullProgressReporter;
use Capell\Core\Support\Install\RegisteredInstallPatch;
use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\AdminUserModelGuard;
use Capell\Installer\Support\InstallGuide\Patches\UserModelPatch;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-user-model-patch-test-' . bin2hex(random_bytes(8));
    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);
    File::deleteDirectory($this->temporaryBasePath);
});

function writeSetupUserModelForPatchTest(string $content): string
{
    $path = base_path('app/Models/User.php');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $content);

    return $path;
}

/** @return array<string, mixed> */
function loadPatchedUserModelForTest(string $path): array
{
    $root = dirname(__DIR__, 6);
    $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', $root . '/packages/core/tests/fixtures/activitylog-runtime.php', $root, $path]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function conventionalUserForPatchTest(string $declaration): string
{
    return '<?php declare(strict_types=1); namespace App\\Models; use Illuminate\\Foundation\\Auth\\User as Authenticatable; ' . $declaration;
}

it('patches only conventional users without activity logging and executes the result', function (string $declaration): void {
    $path = writeSetupUserModelForPatchTest(conventionalUserForPatchTest($declaration));
    $patch = new UserModelPatch;

    expect($patch->probe())->toBe(PatchStatus::Applicable);
    $patch->apply();
    expect(loadPatchedUserModelForTest($path))->toMatchArray([
        'activities' => true, 'trait' => true, 'logged_name' => 'After', 'relation_count' => 2,
    ])->and($patch->probe())->toBe(PatchStatus::AlreadyApplied);

    $contents = File::get($path);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class, 'already_applied');
    expect(File::get($path))->toBe($contents);
})->with([
    'no logging or traits' => 'class User extends Authenticatable {}',
    'final class' => 'final class User extends Authenticatable {}',
    'stock traits and casts' => 'class User extends Authenticatable { use \\Illuminate\\Notifications\\Notifiable; #[\\Override] protected function casts(): array { return ["name" => "string"]; } }',
    'existing Filament user' => 'class User extends Authenticatable implements \\Filament\\Models\\Contracts\\FilamentUser { public function canAccessPanel(\\Filament\\Panel $panel): bool { return true; } }',
    'existing non logging admin traits' => 'class User extends Authenticatable { use \\Spatie\\Permission\\Traits\\HasRoles, \\Capell\\Core\\Models\\Concerns\\HasSitePermissions; }',
    'non colliding grouped import' => 'use Illuminate\\Notifications\\{Notifiable as Notify}; class User extends Authenticatable { use Notify; }',
    'matching grouped admin import' => 'use Spatie\\Permission\\Traits\\{HasRoles}; class User extends Authenticatable { use HasRoles; }',
    'matching aliased admin import' => 'use Spatie\\Permission\\Traits\\HasRoles as Roles; class User extends Authenticatable { use Roles; }',
]);

it('refuses existing logging and unsafe user shapes without changing any bytes', function (string $declaration): void {
    $contents = conventionalUserForPatchTest($declaration);
    $path = writeSetupUserModelForPatchTest($contents);
    $patch = new UserModelPatch;

    expect($patch->probe())->toBe(PatchStatus::Customised)
        ->and($patch->reason())->toBe(__('capell-installer::install-guide.user_model_patch_customised'));
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class, 'customised');
    expect(File::get($path))->toBe($contents);
})->with([
    'v4 imports with portable options' => 'use Spatie\\Activitylog\\Traits\\LogsActivity; use Spatie\\Activitylog\\LogOptions; class User extends Authenticatable { use LogsActivity; public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logAll(); } }',
    'v4 direct names with portable options' => <<<'PHP'
class User extends Authenticatable { use \Spatie\Activitylog\Traits\LogsActivity; public function getActivitylogOptions(): \Spatie\Activitylog\LogOptions { return \Spatie\Activitylog\LogOptions::defaults()->logAll(); } }
PHP,
    'v5 imports with portable options' => 'use Spatie\\Activitylog\\Models\\Concerns\\LogsActivity; use Spatie\\Activitylog\\Support\\LogOptions; class User extends Authenticatable { use LogsActivity; public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logAll(); } }',
    'Core import only' => 'use Capell\\Core\\Support\\Activity\\LogOptions; class User extends Authenticatable {}',
    'incomplete Core preparation' => 'use Capell\\Core\\Support\\Activity\\LogOptions; use Capell\\Core\\Support\\Activity\\LogsActivity; class User extends Authenticatable { use LogsActivity; public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logAll(); } }',
    'both logging traits with precedence' => 'use Capell\\Core\\Support\\Activity\\LogsActivity as CoreLogs; use Spatie\\Activitylog\\Traits\\LogsActivity as VendorLogs; class User extends Authenticatable { use CoreLogs, VendorLogs { CoreLogs::bootLogsActivity insteadof VendorLogs; CoreLogs::activities insteadof VendorLogs; } }',
    'vendor only options' => 'use Spatie\\Activitylog\\LogOptions; class User extends Authenticatable { public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->dontSubmitEmptyLogs(); } }',
    'custom activity hook' => 'class User extends Authenticatable { public function tapActivity(\\stdClass $activity): void {} }',
    'modern activity hook' => 'class User extends Authenticatable { public function beforeActivityLogged(\\stdClass $activity, string $event): void {} }',
    'aliased logging imports' => 'use Spatie\\Activitylog\\Traits\\LogsActivity as Audit; use Spatie\\Activitylog\\LogOptions as AuditOptions; class User extends Authenticatable { use Audit; public function getActivitylogOptions(): AuditOptions { return AuditOptions::defaults()->logAll(); } }',
    'grouped logging imports' => 'use Spatie\\Activitylog\\Traits\\{LogsActivity as Audit}; use Spatie\\Activitylog\\{LogOptions as AuditOptions}; class User extends Authenticatable { use Audit; public function getActivitylogOptions(): AuditOptions { return AuditOptions::defaults()->logAll(); } }',
    'trait adaptation' => 'class User extends Authenticatable { use \\Illuminate\\Notifications\\Notifiable { notify as sendNotice; } }',
    'abstract class' => 'abstract class User extends Authenticatable {}',
    'readonly class' => 'readonly class User extends Authenticatable {}',
    'non default namespace' => 'class User extends Authenticatable {} namespace Custom; class Other {}',
    'multiple classes' => 'class Other {} class User extends Authenticatable {}',
    'no User declaration' => 'class Account extends Authenticatable {}',
    'differently cased User declaration' => 'class user extends Authenticatable {}',
    'unused local LogsActivity trait' => 'trait LogsActivity {} class User extends Authenticatable {}',
    'unused local trait' => 'trait LocalTrait {} class User extends Authenticatable {}',
    'unused local interface' => 'interface LocalContract {} class User extends Authenticatable {}',
    'unused local enum' => 'enum LocalState { case Active; } class User extends Authenticatable {}',
    'namespaced helper with vendor only call' => 'use Spatie\\Activitylog\\LogOptions; function helper(): void { LogOptions::defaults()->dontSubmitEmptyLogs(); } class User extends Authenticatable { public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logAll(); } }',
    'unused helper function' => 'function helper(): void {} class User extends Authenticatable {}',
    'nested helper function' => 'class User extends Authenticatable { #[\\Override] protected function casts(): array { function helper(): void {} return []; } }',
    'vendor only casts with portable options' => 'use Spatie\\Activitylog\\LogOptions; class User extends Authenticatable { public function getActivitylogOptions(): LogOptions { return LogOptions::defaults()->logAll(); } #[\\Override] protected function casts(): array { $options = LogOptions::defaults()->dontSubmitEmptyLogs(); return []; } }',
    'vendor only casts without options' => 'use Spatie\\Activitylog\\LogOptions; class User extends Authenticatable { #[\\Override] protected function casts(): array { $options = LogOptions::defaults()->dontSubmitEmptyLogs(); return []; } }',
    'vendor only panel body' => 'use Spatie\\Activitylog\\LogOptions; class User extends Authenticatable implements \\Filament\\Models\\Contracts\\FilamentUser { public function canAccessPanel(\\Filament\\Panel $panel): bool { LogOptions::defaults()->dontSubmitEmptyLogs(); return true; } }',
    'unused grouped colliding alias' => 'use Illuminate\\Notifications\\{Notifiable as LogsActivity}; class User extends Authenticatable {}',
    'unused lower case colliding alias' => 'use Illuminate\\Notifications\\Notifiable as logsactivity; class User extends Authenticatable {}',
    'unused grouped lower case collision' => 'use Illuminate\\Notifications\\{Notifiable as hasroles}; class User extends Authenticatable {}',
    'unused Activity collision' => 'use Illuminate\\Notifications\\Notifiable as Activity; class User extends Authenticatable {}',
    'unused FilamentUser collision' => 'use Illuminate\\Notifications\\Notifiable as filamentuser; class User extends Authenticatable {}',
    'missing parent' => 'class User {}',
    'custom parent' => 'class User extends CustomBaseUser {}',
    'unknown interface' => 'class User extends Authenticatable implements MissingInterface {}',
    'invalid panel signature' => 'class User extends Authenticatable { protected function canAccessPanel(): string { return "yes"; } }',
    'invalid casts override' => 'class User extends Authenticatable { public static function casts(): array { return []; } }',
    'invalid property type' => 'class User extends Authenticatable { protected string $fillable; }',
    'custom trait' => 'trait CustomTrait {} class User extends Authenticatable { use CustomTrait; }',
    'activity helper call' => 'class User extends Authenticatable { #[\\Override] protected function casts(): array { activity(); return []; } }',
    'activity configuration' => 'class User extends Authenticatable { #[\\Override] protected function casts(): array { config("activitylog.enabled"); return []; } }',
    'dynamic logging class string' => 'class User extends Authenticatable { #[\\Override] protected function casts(): array { $class = "Spatie\\\\Activitylog\\\\LogOptions"; $class::defaults(); return []; } }',
]);

it('refuses a single User in a non default namespace unchanged', function (): void {
    $contents = '<?php namespace Custom; class User extends \\Illuminate\\Foundation\\Auth\\User {}';
    $path = writeSetupUserModelForPatchTest($contents);
    $patch = new UserModelPatch;
    expect($patch->probe())->toBe(PatchStatus::Customised);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class, 'customised');
    expect(File::get($path))->toBe($contents);
});

it('recognises only operational complete Core preparation without rewriting it', function (): void {
    $path = writeSetupUserModelForPatchTest(conventionalUserForPatchTest('class User extends Authenticatable {}'));
    $patch = new UserModelPatch;
    $patch->apply();

    $contents = File::get($path);

    expect(loadPatchedUserModelForTest($path))->toMatchArray(['activities' => true, 'logged_name' => 'After', 'relation_count' => 2])
        ->and($patch->probe())->toBe(PatchStatus::AlreadyApplied);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class, 'already_applied');
    expect(File::get($path))->toBe($contents);
});

it('recognises operational complete preparation with Core aliases and grouped imports', function (string $imports): void {
    $contents = conventionalUserForPatchTest($imports . <<<'PHP'
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Admin\Models\Concerns\HasImpersonation;
use Capell\Core\Models\Concerns\HasSitePermissions;
use Spatie\Permission\Traits\HasRoles;
class User extends Authenticatable implements \Filament\Models\Contracts\FilamentUser {
    use HasPanelShield, HasImpersonation, HasSitePermissions, HasRoles, Audit;
    public function getActivitylogOptions(): AuditOptions {
        return Compat::options('user', ['created_at', 'updated_at']);
    }
}
PHP);
    $path = writeSetupUserModelForPatchTest($contents);
    $patch = new UserModelPatch;
    expect($patch->probe())->toBe(PatchStatus::AlreadyApplied)
        ->and(loadPatchedUserModelForTest($path))->toMatchArray(['activities' => true, 'trait' => true, 'logged_name' => 'After', 'relation_count' => 2]);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class, 'already_applied');
    expect(File::get($path))->toBe($contents);
})->with([
    'aliases' => 'use Capell\\Core\\Support\\Activity\\LogsActivity as Audit; use Capell\\Core\\Support\\Activity\\LogOptions as AuditOptions; use Capell\\Core\\Support\\Activity\\ActivityLogCompat as Compat;',
    'grouped aliases' => 'use Capell\\Core\\Support\\Activity\\{LogsActivity as Audit, LogOptions as AuditOptions, ActivityLogCompat as Compat};',
]);

it('refuses unsafe complete Core users without rewriting them', function (string $replacement): void {
    $path = writeSetupUserModelForPatchTest(conventionalUserForPatchTest('class User extends Authenticatable {}'));
    $patch = new UserModelPatch;
    $patch->apply();

    $contents = preg_replace("/return ActivityLogCompat::options\\('user',.*?;/", $replacement, File::get($path));
    expect($contents)->toBeString();
    File::put($path, $contents);

    expect($patch->probe())->toBe(PatchStatus::Customised);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class, 'customised');
    expect(File::get($path))->toBe($contents);
})->with([
    'v4 only suppression' => 'return LogOptions::defaults()->dontSubmitEmptyLogs();',
    'v5 only suppression' => 'return LogOptions::defaults()->dontLogEmptyChanges();',
    'unknown options call' => 'return LogOptions::defaults()->missingMethod();',
    'invalid options argument' => 'return LogOptions::defaults()->logOnly(42);',
    'unresolved vendor options' => <<<'PHP'
return \Spatie\Activitylog\LogOptions::defaults()->logAll();
PHP,
]);

it('keeps the installed vendor User surface operational without applying the patch', function (): void {
    $trait = ActivityLogCompat::logsActivityTrait();
    $options = ActivityLogCompat::logOptionsClass();
    $legacy = method_exists($options, 'dontSubmitEmptyLogs');
    $suppress = $legacy ? 'dontSubmitEmptyLogs' : 'dontLogEmptyChanges';
    $hook = $legacy ? 'tapActivity' : 'beforeActivityLogged';
    $contents = conventionalUserForPatchTest(<<<PHP
use {$trait};
use {$options};
class User extends Authenticatable {
    use LogsActivity;
    public function getActivitylogOptions(): LogOptions {
        return LogOptions::defaults()->logAll()->useLogName(config('activitylog.default_log_name'))->{$suppress}();
    }
    public function {$hook}(\\Spatie\\Activitylog\\Models\\Activity \$activity, string \$event): void {
        \$activity->properties = collect(['host_hook' => \$event]);
    }
}
PHP);
    $path = writeSetupUserModelForPatchTest($contents);
    $patch = new UserModelPatch;
    expect($patch->probe())->toBe(PatchStatus::Customised);
    expect(loadPatchedUserModelForTest($path))->toMatchArray([
        'activities' => true, 'trait' => false, 'relation_count' => 2, 'host_hook' => 'updated', 'log_name' => 'default',
    ]);
    expect(File::get($path))->toBe($contents);
});

it('reports missing and unparseable users as unsupported without writes', function (): void {
    $patch = new UserModelPatch;
    expect($patch->probe())->toBe(PatchStatus::Unsupported);
    $contents = '<?php class User extends';
    $path = writeSetupUserModelForPatchTest($contents);
    expect($patch->probe())->toBe(PatchStatus::Unsupported);
    expect(File::get($path))->toBe($contents);
});

function preparedUserForAdminReadinessTest(string $trait, string $options, string $expression): string
{
    return conventionalUserForPatchTest(<<<PHP
class User extends Authenticatable implements \\Filament\\Models\\Contracts\\FilamentUser {
    use \\Capell\\Admin\\Models\\Concerns\\HasImpersonation;
    use \\BezhanSalleh\\FilamentShield\\Traits\\HasPanelShield;
    use \\Spatie\\Permission\\Traits\\HasRoles;
    use \\Capell\\Core\\Models\\Concerns\\HasSitePermissions;
    use \\{$trait};

    public function canAccessPanel(\\Filament\\Panel \$panel): bool { return true; }
    public function getActivitylogOptions(): \\{$options} { return {$expression}; }
}
PHP);
}

function adminSelectionForReadinessTest(): InstallInputData
{
    return new InstallInputData(
        siteUrl: 'https://example.test',
        packages: ['capell-app/admin'],
        languages: ['en'],
        demoContent: false,
        cachesToClear: [],
        generateSitemap: false,
        generateStaticSite: false,
    );
}

it('accepts an operational installed-major vendor User through the real admin guard without preparing it', function (): void {
    $trait = ActivityLogCompat::logsActivityTrait();
    $options = ActivityLogCompat::logOptionsClass();
    $suppression = method_exists($options, 'dontSubmitEmptyLogs') ? 'dontSubmitEmptyLogs' : 'dontLogEmptyChanges';
    $contents = preparedUserForAdminReadinessTest($trait, $options, sprintf('\\%s::defaults()->logAll()->%s()', $options, $suppression));
    $path = writeSetupUserModelForPatchTest($contents);

    expect(loadPatchedUserModelForTest($path))->toMatchArray(['logged_name' => 'After', 'relation_count' => 2]);
    (new AdminUserModelGuard)->ensureUserModelSupportsAdminPackage(adminSelectionForReadinessTest(), new NullProgressReporter);

    $patch = new UserModelPatch;
    expect($patch->probe())->toBe(PatchStatus::Customised);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class, 'customised');
    expect(File::get($path))->toBe($contents);

    $registered = resolve(InstallPatchRegistry::class)->patchesFor(new InstallPatchContext(['capell-app/admin'], false));
    expect(array_filter($registered, static fn (RegisteredInstallPatch $entry): bool => $entry->patch instanceof UserModelPatch))->toBeEmpty();
});

it('judges the unchanged legacy vendor User against the installed major in the real admin guard', function (): void {
    $contents = <<<'PHP'
<?php declare(strict_types=1);
namespace App\Models;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Filament\Models\Contracts\FilamentUser;
use BezhanSalleh\FilamentShield\Traits\HasPanelShield;
use Capell\Admin\Models\Concerns\HasImpersonation;
use Capell\Core\Models\Concerns\HasSitePermissions;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
class User extends Authenticatable implements FilamentUser {
    use HasPanelShield, HasImpersonation, HasSitePermissions, HasRoles, LogsActivity;
    public function getActivitylogOptions(): LogOptions {
        return LogOptions::defaults()->logAll()->dontSubmitEmptyLogs();
    }
}
PHP;
    $path = writeSetupUserModelForPatchTest($contents);
    $guard = new AdminUserModelGuard;

    if (method_exists(ActivityLogCompat::logOptionsClass(), 'dontSubmitEmptyLogs')) {
        expect(loadPatchedUserModelForTest($path))->toMatchArray(['logged_name' => 'After', 'relation_count' => 2]);
        $guard->ensureUserModelSupportsAdminPackage(adminSelectionForReadinessTest(), new NullProgressReporter);
    } else {
        expect(fn () => $guard->ensureUserModelSupportsAdminPackage(adminSelectionForReadinessTest(), new NullProgressReporter))
            ->toThrow(RuntimeException::class, __('capell-installer::install-guide.user_model_patch_customised'));
    }

    expect((new UserModelPatch)->probe())->toBe(PatchStatus::Customised);
    expect(File::get($path))->toBe($contents);
});

it('accepts a prepared Core User through the real admin guard without writes on either major', function (): void {
    $contents = preparedUserForAdminReadinessTest(
        'Capell\\Core\\Support\\Activity\\LogsActivity',
        'Capell\\Core\\Support\\Activity\\LogOptions',
        sprintf("\\%s::options('user', ['created_at', 'updated_at'])", ActivityLogCompat::class),
    );
    $path = writeSetupUserModelForPatchTest($contents);

    (new AdminUserModelGuard)->ensureUserModelSupportsAdminPackage(adminSelectionForReadinessTest(), new NullProgressReporter);
    expect(loadPatchedUserModelForTest($path))->toMatchArray(['trait' => true, 'logged_name' => 'After', 'relation_count' => 2]);
    expect(File::get($path))->toBe($contents);
});

it('checks legacy logging references throughout the unchanged User against the installed major', function (string $imports, string $members, bool $vendorTrait): void {
    $contents = preparedUserForAdminReadinessTest(
        $vendorTrait ? 'Spatie\\Activitylog\\Traits\\LogsActivity' : 'Capell\\Core\\Support\\Activity\\LogsActivity',
        'Capell\\Core\\Support\\Activity\\LogOptions',
        sprintf("\\%s::options('user', [])", ActivityLogCompat::class),
    );
    $contents = str_replace('class User extends', $imports . ' class User extends', $contents);
    $contents = substr_replace($contents, $members, strrpos($contents, '}'), 0);

    $path = writeSetupUserModelForPatchTest($contents);
    $legacyInstalled = method_exists(ActivityLogCompat::logOptionsClass(), 'dontSubmitEmptyLogs');
    $patch = new UserModelPatch;

    expect($patch->isReadyForAdmin())->toBe($legacyInstalled);
    if ($legacyInstalled) {
        expect(loadPatchedUserModelForTest($path))->toMatchArray(['logged_name' => 'After', 'relation_count' => 2]);
        (new AdminUserModelGuard)->ensureUserModelSupportsAdminPackage(adminSelectionForReadinessTest(), new NullProgressReporter);
    } else {
        expect(fn () => (new AdminUserModelGuard)->ensureUserModelSupportsAdminPackage(adminSelectionForReadinessTest(), new NullProgressReporter))
            ->toThrow(RuntimeException::class, __('capell-installer::install-guide.user_model_patch_customised'));
    }

    expect($patch->probe())->toBe(PatchStatus::Customised);
    expect(fn () => $patch->apply())->toThrow(RuntimeException::class, 'customised');
    expect(File::get($path))->toBe($contents);
})->with([
    'Core trait with legacy property' => ['', 'protected ?\\Spatie\\Activitylog\\LogOptions $activitylogOptions;', false],
    'vendor trait with legacy property' => ['', 'protected ?\\Spatie\\Activitylog\\LogOptions $activitylogOptions;', true],
    'legacy static call in casts' => ['', <<<'PHP'
#[\Override] protected function casts(): array { \Spatie\Activitylog\LogOptions::defaults()->dontSubmitEmptyLogs(); return []; }
PHP, false],
    'legacy parameter type' => ['', 'public function audit(\\Spatie\\Activitylog\\LogOptions $options): void {}', false],
    'legacy class constant' => ['', <<<'PHP'
private const string OPTIONS = \Spatie\Activitylog\LogOptions::class;
PHP, false],
    'unused legacy import' => ['use Spatie\\Activitylog\\LogOptions as AuditOptions;', '', false],
    'unused grouped legacy import' => ['use Spatie\\Activitylog\\{LogOptions as AuditOptions};', '', false],
    'Core alias with legacy method in casts' => ['', '#[\\Override] protected function casts(): array { \\Capell\\Core\\Support\\Activity\\LogOptions::defaults()->dontSubmitEmptyLogs(); return []; }', false],
    'Core alias parameter with legacy method' => ['', 'public function audit(\\Capell\\Core\\Support\\Activity\\LogOptions $options): void { $options->dontSubmitEmptyLogs(); }', false],
    'Core alias union parameter with legacy method' => ['', 'public function audit(\\Capell\\Core\\Support\\Activity\\LogOptions|\\stdClass $options): void { $options->dontSubmitEmptyLogs(); }', false],
    'Core options method result with legacy method' => ['', '#[\\Override] protected function casts(): array { $this->getActivitylogOptions()->dontSubmitEmptyLogs(); return []; }', false],
    'Core alias variable with legacy method' => ['', '#[\\Override] protected function casts(): array { $options = \\Capell\\Core\\Support\\Activity\\LogOptions::defaults(); $options->dontSubmitEmptyLogs(); return []; }', false],
    'legacy reference outside User' => ['', '} function audit(\\Spatie\\Activitylog\\LogOptions $options): void {', false],
]);

it('rejects installed-major vendor Users with invalid options through the real admin guard unchanged', function (string $expression): void {
    $options = ActivityLogCompat::logOptionsClass();
    $contents = preparedUserForAdminReadinessTest(ActivityLogCompat::logsActivityTrait(), $options, sprintf('\\%s::defaults()->%s', $options, $expression));
    $path = writeSetupUserModelForPatchTest($contents);

    expect(fn () => (new AdminUserModelGuard)->ensureUserModelSupportsAdminPackage(adminSelectionForReadinessTest(), new NullProgressReporter))
        ->toThrow(RuntimeException::class, __('capell-installer::install-guide.user_model_patch_customised'));
    expect(File::get($path))->toBe($contents);
})->with(['unknown method' => 'missingMethod()', 'invalid argument' => 'logOnly(42)']);

it('does not mistake an inoperative admin User declaration for readiness', function (string $search, string $replacement): void {
    $contents = preparedUserForAdminReadinessTest(
        'Capell\\Core\\Support\\Activity\\LogsActivity',
        'Capell\\Core\\Support\\Activity\\LogOptions',
        sprintf("\\%s::options('user', [])", ActivityLogCompat::class),
    );
    $contents = str_replace($search, $replacement, $contents);
    if (str_starts_with($replacement, 'if (false)')) {
        $contents .= '}';
    }

    $path = writeSetupUserModelForPatchTest($contents);

    expect((new UserModelPatch)->isReadyForAdmin())->toBeFalse();
    expect(fn () => (new AdminUserModelGuard)->ensureUserModelSupportsAdminPackage(adminSelectionForReadinessTest(), new NullProgressReporter))
        ->toThrow(RuntimeException::class, __('capell-installer::install-guide.user_model_patch_customised'));
    expect(File::get($path))->toBe($contents);
})->with([
    'missing admin trait' => ['use \\Spatie\\Permission\\Traits\\HasRoles;', ''],
    'wrong namespace' => ['namespace App\\Models;', 'namespace Other;'],
    'conditional declaration' => ['class User extends', 'if (false) { class User extends'],
    'invalid panel override' => ['public function canAccessPanel(\\Filament\\Panel $panel): bool', 'protected function canAccessPanel(): string'],
    'both logging traits' => ['use \\Capell\\Core\\Support\\Activity\\LogsActivity;', 'use \\Capell\\Core\\Support\\Activity\\LogsActivity, \\Spatie\\Activitylog\\Traits\\LogsActivity;'],
]);
