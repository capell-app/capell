<?php

declare(strict_types=1);

use Capell\Core\Support\Filesystem\AbsolutePath;

it('recognises absolute paths on every supported host', function (string $path): void {
    expect(AbsolutePath::is($path))->toBeTrue();
})->with([
    'unix path' => '/var/www/capell',
    'unix root' => '/',
    'windows drive with backslashes' => 'C:\\inetpub\\capell',
    'windows drive with forward slashes' => 'C:/inetpub/capell',
    'lower case windows drive' => 'd:\\sites\\capell',
    'unc share' => '\\\\fileserver\\releases\\capell',
]);

it('rejects paths that are not anchored to a filesystem root', function (string $path): void {
    expect(AbsolutePath::is($path))->toBeFalse();
})->with([
    'empty' => '',
    'relative directory' => 'packages/core',
    'relative with parent traversal' => '../outside',
    'relative windows style' => 'packages\\core',
    'drive relative' => 'C:packages',
    'single leading backslash' => '\\packages',
    'bare drive letter' => 'C:',
]);

it('reports the root prefix an absolute path is anchored to', function (string $path, ?string $expected): void {
    expect(AbsolutePath::rootPrefix($path))->toBe($expected);
})->with([
    'unix path' => ['/var/www/capell', '/'],
    'windows drive with backslashes' => ['C:\\inetpub\\capell', 'C:\\'],
    'windows drive with forward slashes' => ['C:/inetpub/capell', 'C:/'],
    'unc share' => ['\\\\fileserver\\releases', '\\\\'],
    'relative path' => ['packages/core', null],
]);

it('treats backslashes as separators only for windows rooted paths on a unix host', function (): void {
    expect(AbsolutePath::hasWindowsSeparators('C:\\inetpub\\capell'))->toBeTrue()
        ->and(AbsolutePath::hasWindowsSeparators('\\\\fileserver\\releases'))->toBeTrue()
        ->and(AbsolutePath::hasWindowsSeparators('/var/www/capell'))->toBe(DIRECTORY_SEPARATOR === '\\');
});
