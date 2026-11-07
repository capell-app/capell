<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Finds <button> and <a> elements in admin Blade views whose only content is an icon,
 * and requires an accessible name on the element itself.
 *
 * @return list<string> "path:line" of every unnamed icon-only control
 */
function unnamedIconOnlyControls(string $directory): array
{
    $unnamed = [];
    $files = Finder::create()->files()->in($directory)->name('*.blade.php');

    foreach ($files as $file) {
        $source = (string) file_get_contents($file->getRealPath());
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        if (preg_match_all('/<(button|a)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)<\/\1>/s', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            continue;
        }

        foreach ($matches as $match) {
            $attributes = $match[2][0];
            $inner = $match[3][0];
            $visible = preg_replace(['/<svg\b.*?<\/svg>/s', '/<x-[\w:.\-]*icon[\w:.\-]*\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*\/?>/s', '/<x-[\w:.\-]*icon[\w:.\-]*>.*?<\/x-[\w:.\-]*icon[\w:.\-]*>/s', '/<\/?(?:span|i)\b[^>]*>/'], '', $inner);
            $visible = trim((string) preg_replace('/\s+/', ' ', (string) $visible));
            $hasIcon = preg_match('/<svg\b|<x-[\w:.\-]*icon/i', $inner) === 1;
            if (! $hasIcon) {
                continue;
            }

            if ($visible !== '') {
                continue;
            }

            if (preg_match('/(?:aria-label|aria-labelledby|:aria-label|x-bind:aria-label)\s*=/', $attributes) === 1) {
                continue;
            }

            $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
            $unnamed[] = $file->getRelativePathname() . ':' . $line;
        }
    }

    return $unnamed;
}

it('gives every icon-only button and link in admin views an accessible name', function (): void {
    expect(unnamedIconOnlyControls(dirname(__DIR__, 3) . '/resources/views'))->toBe([]);
});

it('flags icon-only controls without a name and accepts named or text-labelled ones', function (): void {
    $directory = sys_get_temp_dir() . '/icon-guard-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/x.blade.php', implode("\n", [
        '<button type="button"><x-filament::icon icon="heroicon-o-plus" /></button>',
        '<a href="/x" title="Visit"><svg viewBox="0 0 1 1"></svg></a>',
        '<button type="button" aria-label="Add"><x-filament::icon icon="heroicon-o-plus" /></button>',
        '<button type="button"><svg></svg> Add page</button>',
        '<button type="button"><x-filament::icon icon="heroicon-o-plus" /><span class="sr-only">Add</span></button>',
        '{{-- <button><svg></svg></button> --}}',
    ]));

    try {
        expect(unnamedIconOnlyControls($directory))->toBe(['x.blade.php:1', 'x.blade.php:2']);
    } finally {
        unlink($directory . '/x.blade.php');
        rmdir($directory);
    }
});
