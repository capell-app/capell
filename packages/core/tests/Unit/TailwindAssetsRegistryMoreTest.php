<?php

declare(strict_types=1);

use Capell\Core\Support\Tailwind\TailwindAssetsRegistry;

it('ignores empty and whitespace-only entries and preserves origins in report', function (): void {
    $registry = new TailwindAssetsRegistry;

    $registry
        ->registerSource('', 'x')
        ->registerSource('  ', 'y')
        ->registerImport('', 'a')
        ->registerImport('   ', 'b')
        ->registerPlugin('', 'c')
        ->registerPlugin('   ', 'd')
        ->registerSources(['', '  ', 'views/**/*.blade.php'], 'pkg')
        ->registerImports(['', '  ', 'alpha.css'], 'cfg')
        ->registerPlugins(['', '  ', '@tailwindcss/form-builder'], 'prov');

    expect($registry->sources()->all())->toBe(['views/**/*.blade.php']);
    expect($registry->imports()->all())->toBe(['alpha.css']);
    expect($registry->plugins()->all())->toBe(['@tailwindcss/form-builder']);

    expect($registry->toReport())->toMatchArray([
        'sources' => [
            ['value' => 'views/**/*.blade.php', 'origin' => 'pkg'],
        ],
        'imports' => [
            ['value' => 'alpha.css', 'origin' => 'cfg'],
        ],
        'plugins' => [
            ['value' => '@tailwindcss/form-builder', 'origin' => 'prov'],
        ],
    ]);
});
