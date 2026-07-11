<?php

declare(strict_types=1);

use Capell\Core\Support\Patching\ConfigArrayEditor;
use Capell\Core\Support\Patching\PhpFileEditor;
use PhpParser\Node\Scalar\String_;

it('detects and inserts nested config array keys', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_config_');
    file_put_contents($path, <<<'PHP'
<?php

return [
    'disks' => [
        'local' => [
            'driver' => 'local',
        ],
    ],
];
PHP);

    try {
        $editor = new PhpFileEditor($path);
        $config = new ConfigArrayEditor($editor);

        expect($config->hasKey('disks'))->toBeTrue()
            ->and($config->hasKey('disks.local'))->toBeTrue()
            ->and($config->hasKey('disks.page_cache'))->toBeFalse();

        $config->insertKey('disks.page_cache', new String_('cached-pages'));
        $editor->save();

        $content = (string) file_get_contents($path);
        expect($content)->toContain("'page_cache' => 'cached-pages'")
            ->and(new ConfigArrayEditor(new PhpFileEditor($path))->hasKey('disks.page_cache'))->toBeTrue();
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('rejects invalid config insertions', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'capell_config_');
    file_put_contents($path, <<<'PHP'
<?php

return [
    'mail' => 'smtp',
];
PHP);

    try {
        $config = new ConfigArrayEditor(new PhpFileEditor($path));

        expect(fn (): mixed => $config->insertKey('mail.driver', new String_('log')))
            ->toThrow(RuntimeException::class, "Root key 'mail' does not contain an array");

        expect(fn (): mixed => $config->insertKey('queue.connections', new String_('database')))
            ->toThrow(RuntimeException::class, "Root key 'queue' not found");

        expect(fn (): mixed => $config->insertKey('mail', new String_('smtp')))
            ->toThrow(RuntimeException::class, 'Invalid array path');
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});
