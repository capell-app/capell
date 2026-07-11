<?php

declare(strict_types=1);

use Capell\Core\Support\Migration\MigrationFilesystem;

it('perform-builder file and directory operations', function (): void {
    $manager = new MigrationFilesystem;
    $tmpDir = sys_get_temp_dir() . '/mfm_' . uniqid();
    $tmpFile = $tmpDir . '/test.txt';
    $copyFile = $tmpDir . '/copy.txt';

    expect($manager->isDir($tmpDir))->toBeFalse();
    $manager->makeDir($tmpDir);
    expect($manager->isDir($tmpDir))->toBeTrue();

    file_put_contents($tmpFile, 'abc');
    expect($manager->fileExists($tmpFile))->toBeTrue();

    $manager->copy($tmpFile, $copyFile);
    expect($manager->fileExists($copyFile))->toBeTrue();
    expect(file_get_contents($copyFile))->toBe('abc');

    unlink($tmpFile);
    unlink($copyFile);
    rmdir($tmpDir);
});
