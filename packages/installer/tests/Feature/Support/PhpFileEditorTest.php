<?php

declare(strict_types=1);

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Core\Support\Patching\PhpFileEditor;
use Capell\Tests\Support\GeneratedPhpFixture;
use Capell\Tests\Support\GeneratedPhpOutcome;
use Carbon\Carbon;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Support\Str;

it('makes newly imported classes available without changing existing provider behaviour', function (): void {
    $path = temporaryPhpEditorFile(<<<'PHP'
<?php
namespace App\Providers\Filament;
use Filament\Panel;
use Filament\PanelProvider;
use Override;
class AdminPanelProvider extends PanelProvider
{
    #[Override]
    public function panel(Panel $panel): Panel
    {
        return $panel->default(  )->id( 'admin' )->path('admin')->login( )->plugins([CapellAdminPlugin::make()]);
    }
}
PHP);
    try {
        new PhpFileEditor($path)->addUseStatements([CapellAdminPlugin::class])->save();
        $provider = GeneratedPhpFixture::load($path, PanelProvider::class, app());
        $panel = $provider->panel(Panel::make());
        expect($panel->getId())->toBe('admin')
            ->and($panel->getPath())->toBe('admin')
            ->and($panel->isDefault())->toBeTrue()
            ->and($panel->hasLogin())->toBeTrue()
            ->and($panel->hasPlugin(CapellAdminPlugin::make()->getId()))->toBeTrue();
    } finally {
        unlink($path);
    }
});

it('removes imported aliases while preserving unrelated imports and class methods', function (): void {
    $path = temporaryPhpEditorFile(<<<'PHP'
<?php
namespace App\Providers\Filament;
use Carbon\Carbon;
use Illuminate\Support\Str;
class InstallerFixture implements \Capell\Tests\Support\GeneratedPhpOutcome
{
    public function handle(): array
    {
        return [Carbon::class, Str::upper('installed')];
    }
}
PHP);
    try {
        new PhpFileEditor($path)->removeUseStatements([Carbon::class])->save();
        $fixture = GeneratedPhpFixture::load($path, GeneratedPhpOutcome::class);
        expect($fixture->handle())->toBe([substr($fixture::class, 0, strrpos($fixture::class, '\\')) . '\\Carbon', 'INSTALLED']);
    } finally {
        unlink($path);
    }
});

it('keeps a usable original backup while applying imports to a global php class', function (): void {
    $path = temporaryPhpEditorFile(<<<'PHP'
<?php
use Carbon\Carbon;
class InstallerFixture implements \Capell\Tests\Support\GeneratedPhpOutcome
{
    public function handle(): array
    {
        return [Carbon::parse('2026-10-10')->toDateString(), Str::class];
    }
}
PHP);
    try {
        $editor = new PhpFileEditor($path);
        $backup = $editor->backup();
        $editor->addUseStatements([Str::class])->save();
        $original = GeneratedPhpFixture::load($backup, GeneratedPhpOutcome::class);
        $updated = GeneratedPhpFixture::load($path, GeneratedPhpOutcome::class);
        expect($original->handle()[0])->toBe('2026-10-10')
            ->and($updated->handle())->toBe(['2026-10-10', Str::class])
            ->and($original->handle()[1])->not->toBe(Str::class);
    } finally {
        unlink($path);
        if (isset($backup)) {
            unlink($backup);
        }
    }
});

it('throws when php file save cannot be written', function (): void {
    $testFilePath = temporaryPhpEditorFile(<<<'PHP'
<?php

declare(strict_types=1);

class InstallerFixture
{
}
PHP);

    try {
        $editor = new PhpFileEditor($testFilePath);

        chmod($testFilePath, 0400);

        expect(fn (): null => $editor->save())
            ->toThrow(RuntimeException::class, 'Failed to write PHP file at path');

        expect(file_get_contents($testFilePath))->toBe($editor->originalContent());
    } finally {
        if (file_exists($testFilePath)) {
            chmod($testFilePath, 0600);
            unlink($testFilePath);
        }
    }
});

it('throws when php file backup cannot read the source file', function (): void {
    $testFilePath = temporaryPhpEditorFile(<<<'PHP'
<?php

declare(strict_types=1);

class InstallerFixture
{
}
PHP);

    try {
        $editor = new PhpFileEditor($testFilePath);

        chmod($testFilePath, 0200);

        expect(fn (): string => $editor->backup())
            ->toThrow(RuntimeException::class, 'Failed to back up PHP file to path');
    } finally {
        if (file_exists($testFilePath)) {
            chmod($testFilePath, 0600);
            unlink($testFilePath);
        }
    }
});

it('fails clearly for missing and invalid php files', function (): void {
    expect(fn (): PhpFileEditor => new PhpFileEditor(sys_get_temp_dir() . '/missing-capell-php-editor-file.php'))
        ->toThrow(RuntimeException::class, 'File does not exist');

    $invalidFilePath = temporaryPhpEditorFile("<?php\nclass Broken {");

    try {
        expect(fn (): PhpFileEditor => new PhpFileEditor($invalidFilePath))
            ->toThrow(RuntimeException::class, 'Failed to parse PHP file');
    } finally {
        if (file_exists($invalidFilePath)) {
            unlink($invalidFilePath);
        }
    }
});

function temporaryPhpEditorFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'capell_php_editor_');
    file_put_contents($path, $content);

    return $path;
}
