<?php

declare(strict_types=1);

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Capell\Core\Support\Patching\PhpFileEditor;
use Carbon\Carbon;
use Illuminate\Support\Str;

test('adds_use_statement_without_reformatting_existing_code', function (): void {
    $testProviderPath = tempnam(sys_get_temp_dir(), 'test_provider_');
    $originalContent = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default(  )
            ->id( 'admin' )
            ->path('admin')
            // Existing applications can have hand-formatted chains.
            ->login( );
    }
}
PHP;

    $expectedContent = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use Capell\Admin\Filament\Plugin\CapellAdminPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default(  )
            ->id( 'admin' )
            ->path('admin')
            // Existing applications can have hand-formatted chains.
            ->login( );
    }
}
PHP;

    file_put_contents($testProviderPath, $originalContent);

    try {
        new PhpFileEditor($testProviderPath)
            ->addUseStatements([CapellAdminPlugin::class])
            ->save();

        expect(file_get_contents($testProviderPath))->toBe($expectedContent);
    } finally {
        if (file_exists($testProviderPath)) {
            unlink($testProviderPath);
        }
    }
});

test('removes namespaced use statements before admin panel patches rewrite providers', function (): void {
    $testProviderPath = temporaryPhpEditorFile(<<<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Support\LegacyPlugin;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->plugins([
            LegacyPlugin::make(),
        ]);
    }
}
PHP);

    try {
        $editor = new PhpFileEditor($testProviderPath);

        expect($editor->findClass('AdminPanelProvider'))->not->toBeNull()
            ->and($editor->findMethodInClass('AdminPanelProvider', 'panel'))->not->toBeNull()
            ->and($editor->findMethodInClass('MissingProvider', 'panel'))->toBeNull();

        $editor
            ->setAst($editor->getAst())
            ->removeUseStatements([CapellAdminPlugin::class, 'App\\Support\\LegacyPlugin'])
            ->save();

        expect(file_get_contents($testProviderPath))
            ->not->toContain('use App\\Support\\LegacyPlugin;')
            ->toContain('use Filament\\Panel;')
            ->toContain('LegacyPlugin::make()');
    } finally {
        if (file_exists($testProviderPath)) {
            unlink($testProviderPath);
        }
    }
});

test('edits global php files with sorted imports and backup copies', function (): void {
    $testFilePath = temporaryPhpEditorFile(<<<'PHP'
<?php

declare(strict_types=1);

use Carbon\Carbon;

class InstallerFixture
{
    public function handle(): string
    {
        return Carbon::now()->toDateTimeString();
    }
}
PHP);

    try {
        $editor = new PhpFileEditor($testFilePath);
        $backupPath = $editor->backup();

        $editor
            ->addUseStatements([
                Str::class,
                'App\\Support\\Alpha',
            ])
            ->removeUseStatements([Carbon::class])
            ->save();

        $content = file_get_contents($testFilePath);

        expect($backupPath)->toBeFile()
            ->and(file_get_contents($backupPath))->toContain('use Carbon\\Carbon;')
            ->and($content)->toContain('use App\\Support\\Alpha;')
            ->toContain('use Illuminate\\Support\\Str;')
            ->not->toContain('use Carbon\\Carbon;')
            ->and(strpos((string) $content, 'use App\\Support\\Alpha;'))
            ->toBeLessThan(strpos((string) $content, 'use Illuminate\\Support\\Str;'));
    } finally {
        if (file_exists($testFilePath)) {
            unlink($testFilePath);
        }
    }
});

test('throws when php file save cannot be written', function (): void {
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

        expect(file_get_contents($testFilePath))->toContain('class InstallerFixture');
    } finally {
        if (file_exists($testFilePath)) {
            chmod($testFilePath, 0600);
            unlink($testFilePath);
        }
    }
});

test('throws when php file backup cannot read the source file', function (): void {
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

test('fails clearly for missing and invalid php files', function (): void {
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
