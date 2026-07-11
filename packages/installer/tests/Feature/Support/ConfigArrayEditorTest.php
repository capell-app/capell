<?php

declare(strict_types=1);

use Capell\Installer\Support\Patching\ConfigArrayEditor;
use Capell\Installer\Support\Patching\PhpFileEditor;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Scalar\String_;

test('reads and edits package config arrays used by installer guide patches', function (): void {
    $configPath = temporaryInstallerConfigFile(<<<'PHP'
<?php

declare(strict_types=1);

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
        ],
    ],
];
PHP);

    try {
        $editor = new PhpFileEditor($configPath);
        $config = new ConfigArrayEditor($editor);

        expect($config->hasKey('default'))->toBeTrue()
            ->and($config->hasKey('disks.local'))->toBeTrue()
            ->and($config->hasKey('disks.page_cache'))->toBeFalse()
            ->and($config->hasKey('missing'))->toBeFalse();

        $config
            ->insertKey('disks.page_cache', new Array_([
                new ArrayItem(new String_('local'), new String_('driver')),
            ]));

        $editor->save();

        expect(file_get_contents($configPath))
            ->toContain("'page_cache'")
            ->toContain("'driver' => 'local'");
    } finally {
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    }
});

test('rejects unsupported config array shapes before installer patches write them', function (): void {
    $nonConfigPath = temporaryInstallerConfigFile("<?php\n\ndeclare(strict_types=1);\n\nreturn 'not-an-array';\n");
    $scalarRootPath = temporaryInstallerConfigFile(<<<'PHP'
<?php

declare(strict_types=1);

return [
    'disks' => 'local',
];
PHP);
    $missingRootPath = temporaryInstallerConfigFile(<<<'PHP'
<?php

declare(strict_types=1);

return [
    'channels' => [],
];
PHP);

    try {
        expect(new ConfigArrayEditor(new PhpFileEditor($nonConfigPath))->hasKey('disks.local'))->toBeFalse()
            ->and(new ConfigArrayEditor(new PhpFileEditor($scalarRootPath))->hasKey('disks.local'))->toBeFalse();

        expect(fn (): ConfigArrayEditor => new ConfigArrayEditor(new PhpFileEditor($scalarRootPath))
            ->insertKey('disks.page_cache', new Array_))
            ->toThrow(RuntimeException::class, "Root key 'disks' does not contain an array");

        expect(fn (): ConfigArrayEditor => new ConfigArrayEditor(new PhpFileEditor($missingRootPath))
            ->insertKey('disks.page_cache', new Array_))
            ->toThrow(RuntimeException::class, "Root key 'disks' not found");
    } finally {
        foreach ([$nonConfigPath, $scalarRootPath, $missingRootPath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }
});

function temporaryInstallerConfigFile(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'capell_config_editor_');
    file_put_contents($path, $content);

    return $path;
}
