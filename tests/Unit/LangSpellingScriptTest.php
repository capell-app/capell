<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** @return array{int|null, string} */
function runLangSpellingFixture(string $language, string $otherSource = ''): array
{
    $root = sys_get_temp_dir() . '/capell-lang-spelling-' . bin2hex(random_bytes(8));
    $filesystem = new Filesystem;
    $filesystem->makeDirectory($root . '/packages/example/resources/lang/en', 0755, true);
    $filesystem->put($root . '/packages/example/resources/lang/en/fixture.php', $language);
    $filesystem->put($root . '/packages/example/Other.php', $otherSource);

    try {
        $process = new Process([PHP_BINARY, 'scripts/check-lang-spelling.php'], dirname(__DIR__, 2), [
            'CAPELL_LANG_SPELLING_ROOT' => $root,
        ]);
        $process->run();

        return [$process->getExitCode(), $process->getOutput() . $process->getErrorOutput()];
    } finally {
        $filesystem->deleteDirectory($root);
    }
}

it('rejects American spellings in nested English language values', function (string $american): void {
    [$exit, $output] = runLangSpellingFixture('<?php return ["nested" => ["label" => ' . var_export($american, true) . ']];');

    expect($exit)->toBe(1)
        ->and($output)->toContain('resources/lang/en/fixture.php:1:', '"' . $american . '" must use British spelling');
})->with([
    'Color', 'colors', 'Organization', 'organizations', 'Optimize', 'optimized', 'optimizer', 'optimization',
    'Customize', 'customizing', 'customization', 'Catalog', 'catalogs', 'Behavior', 'behavioral',
    'Center', 'centers', 'Canceled', 'canceling', 'License', 'licenses',
]);

it('accepts British values, product names and identifiers outside editorial values', function (): void {
    [$exit, $output] = runLangSpellingFixture(<<<'LANG'
<?php
// Customize organization colors in the Privacy Center.
return [
    'colors' => 'Colours',
    'customize' => 'Customise :color in Privacy Center',
    'nested' => ['license' => 'Organisation, optimise, catalogue, behaviour, centre, cancelled and licence.'],
    'verb' => 'Licensed software and licensing details',
];
LANG, '<?php $color = "American colors outside lang values";');

    expect($exit)->toBe(0)
        ->and($output)->toContain('1 English language files');
});

it('still checks the rest of a value that mentions a product name', function (): void {
    [$exit, $output] = runLangSpellingFixture('<?php return ["label" => "Privacy Center colors"];');

    expect($exit)->toBe(1)
        ->and($output)->toContain('"colors" must use British spelling')
        ->not->toContain('"Center" must use British spelling');
});
