<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\PatchStatus;
use Capell\Installer\Support\InstallGuide\Patches\AdminPanelProviderPatcher;
use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Scalar\String_;

beforeEach(function (): void {
    $this->originalBasePath = $this->app->basePath();
    $this->temporaryBasePath = sys_get_temp_dir() . '/capell-admin-panel-provider-patcher-test-' . uniqid();

    File::makeDirectory($this->temporaryBasePath, 0755, true);
    $this->app->setBasePath($this->temporaryBasePath);
});

afterEach(function (): void {
    $this->app->setBasePath($this->originalBasePath);

    if (is_dir($this->temporaryBasePath)) {
        File::deleteDirectory($this->temporaryBasePath);
    }
});

function writeAdminPanelProviderPatcherFixture(string $contents): string
{
    $path = base_path('app/Providers/Filament/AdminPanelProvider.php');

    File::ensureDirectoryExists(dirname($path));
    File::put($path, $contents);

    return $path;
}

it('probe returns unsupported when the provider file does not exist', function (): void {
    $patcher = new AdminPanelProviderPatcher;

    expect($patcher->probe(fn (): PatchStatus => PatchStatus::Applicable))->toBe(PatchStatus::Unsupported);
});

it('probe returns customised when the panel method has more than one statement', function (): void {
    writeAdminPanelProviderPatcherFixture(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $config = config('app.debug');

        return $panel->default()->id('admin');
    }
}
PHP);

    $patcher = new AdminPanelProviderPatcher;

    expect($patcher->probe(fn (): PatchStatus => PatchStatus::Applicable))->toBe(PatchStatus::Customised);
});

it('probe hands the return statement to the decide callback for a stock method chain', function (): void {
    writeAdminPanelProviderPatcherFixture(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin');
    }
}
PHP);

    $patcher = new AdminPanelProviderPatcher;
    $receivedStmt = null;

    $patcher->probe(function (Node $stmt) use (&$receivedStmt): PatchStatus {
        $receivedStmt = $stmt;

        return PatchStatus::Applicable;
    });

    expect($receivedStmt)->not->toBeNull()
        ->and($patcher->hasMethodCall($receivedStmt, 'id'))->toBeTrue()
        ->and($patcher->hasMethodCall($receivedStmt, 'widgets'))->toBeFalse();
});

it('apply refuses to mutate when the decide callback reports the patch is not applicable', function (): void {
    writeAdminPanelProviderPatcherFixture(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin');
    }
}
PHP);

    $patcher = new AdminPanelProviderPatcher;

    expect(fn () => $patcher->apply(
        fn (): PatchStatus => PatchStatus::AlreadyApplied,
        fn (Node $stmt): mixed => null,
    ))->toThrow(RuntimeException::class);
});

it('apply mutates the panel method and persists the file', function (): void {
    $path = writeAdminPanelProviderPatcherFixture(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin');
    }
}
PHP);

    $patcher = new AdminPanelProviderPatcher;

    $patcher->apply(
        fn (): PatchStatus => PatchStatus::Applicable,
        function (Node $stmt) use ($patcher): void {
            $patcher->appendMethodCall($stmt, 'widgets', []);
        },
    );

    expect(File::get($path))->toContain('->widgets()');
});

it('insertMethodCallAfter splices a new call into the middle of the chain', function (): void {
    writeAdminPanelProviderPatcherFixture(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')->login();
    }
}
PHP);

    $patcher = new AdminPanelProviderPatcher;
    $receivedStmt = null;

    $patcher->probe(function (Node $stmt) use (&$receivedStmt): PatchStatus {
        $receivedStmt = $stmt;

        return PatchStatus::Applicable;
    });

    $patcher->insertMethodCallAfter(
        $receivedStmt,
        'path',
        fn (MethodCall $call): MethodCall => new MethodCall($call, 'colors', [new Arg(new String_('bar'))]),
    );

    expect($patcher->hasMethodCall($receivedStmt, 'colors'))->toBeTrue()
        ->and($patcher->findMethodCall($receivedStmt, 'colors'))->not->toBeNull();
});
