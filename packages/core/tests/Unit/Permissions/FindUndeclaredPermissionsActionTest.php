<?php

declare(strict_types=1);

use Capell\Core\Actions\Permissions\FindUndeclaredPermissionsAction;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->packagePath = sys_get_temp_dir() . '/capell-undeclared-' . bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->packagePath . '/src/Filament/Resources');
    File::ensureDirectoryExists($this->packagePath . '/src/Filament/Pages');
    File::ensureDirectoryExists($this->packagePath . '/resources/views');
    File::ensureDirectoryExists($this->packagePath . '/routes');
    File::put($this->packagePath . '/src/Filament/Resources/ReportResource.php', '<?php final class ReportResource { protected static ?string $model = \\Vendor\\Models\\FinanceReport::class; }');
    File::put($this->packagePath . '/src/Filament/Pages/ReportsDashboard.php', '<?php final class ReportsDashboard {}');
});

afterEach(function (): void {
    File::deleteDirectory($this->packagePath);
});

it('finds single-argument abilities the package does not declare', function (): void {
    File::put($this->packagePath . '/src/Service.php', <<<'PHP'
        <?php
        $user->can('reports.view');
        $user->cannot("reports.export");
        Gate::allows('reports.publish');
        $this->authorize('reports.delete');
        PHP);
    File::put($this->packagePath . '/resources/views/list.blade.php', "@can('reports.share') x @endcan");
    File::put($this->packagePath . '/routes/web.php', "<?php Route::get('/r', R::class)->middleware('can:reports.download');");

    expect(FindUndeclaredPermissionsAction::run($this->packagePath, ['reports.view', 'reports.publish']))
        ->toBe(['reports.delete', 'reports.download', 'reports.export', 'reports.share']);
});

it('leaves policy checks with a model or class argument to the policy', function (): void {
    File::put($this->packagePath . '/src/Service.php', <<<'PHP'
        <?php
        $user->can('update', $report);
        Gate::authorize('viewAny', Report::class);
        $this->authorize('delete', [$report, $site]);
        PHP);

    expect(FindUndeclaredPermissionsAction::run($this->packagePath, []))->toBe([]);
});

it('accepts Shield keys only for the subjects Shield generates for the package', function (): void {
    File::put($this->packagePath . '/src/Service.php', <<<'PHP'
        <?php
        $user->can('View:FinanceReport');
        $user->can('View:ReportsDashboard');
        $user->can('View:Report');
        $user->can('View:Invoice');
        PHP);

    expect(FindUndeclaredPermissionsAction::run($this->packagePath, []))->toBe(['View:Invoice', 'View:Report']);
});

it('finds checks written with trailing commas, Gate instances and ability lists', function (): void {
    File::put($this->packagePath . '/src/Service.php', <<<'PHP'
        <?php
        $user->can(
            'reports.trail',
        );
        Gate::forUser($user)->allows('reports.for-user');
        Gate::any(['reports.any-a', "reports.any-b"]);
        $user->canAny(['reports.can-any']);
        PHP);
    File::put($this->packagePath . '/resources/views/list.blade.php', "@canany(['reports.blade-any']) x @elsecan('reports.else') y @endcanany");

    expect(FindUndeclaredPermissionsAction::run($this->packagePath, []))->toBe([
        'reports.any-a',
        'reports.any-b',
        'reports.blade-any',
        'reports.can-any',
        'reports.else',
        'reports.for-user',
        'reports.trail',
    ]);
});

it('ignores commented-out checks and policy middleware', function (): void {
    File::put($this->packagePath . '/src/Service.php', <<<'PHP'
        <?php
        // $user->can('reports.old');
        /** Calls $user->can('reports.documented') when shown. */
        Route::get('/r', R::class)->middleware('can:update,report');
        PHP);
    File::put($this->packagePath . '/resources/views/list.blade.php', "{{-- @can('reports.blade-old') --}}");

    expect(FindUndeclaredPermissionsAction::run($this->packagePath, []))->toBe([]);
});

it('leaves bare policy method names to the record policy', function (): void {
    File::put($this->packagePath . '/src/Service.php', <<<'PHP'
        <?php
        Action::make('approve')->authorize('approve');
        Action::make('save')->authorize('update');
        $user->can('reports.view');
        PHP);

    expect(FindUndeclaredPermissionsAction::run($this->packagePath, []))->toBe(['reports.view']);
});

it('skips a check declared as a token ability with a reason, and only that check', function (): void {
    File::put($this->packagePath . '/src/Service.php', <<<'PHP'
        <?php
        // capell-permission-scan: token-ability Sanctum preview scope, not a role permission
        $allowed = $token->can('api:preview');
        $deleted = $token->can('api:preview:deleted');
        // capell-permission-scan: token-ability
        $restricted = $token->can('api:preview:restricted');
        PHP);

    expect(FindUndeclaredPermissionsAction::run($this->packagePath, []))->toBe(['api:preview:deleted', 'api:preview:restricted']);
});
