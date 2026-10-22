<?php

declare(strict_types=1);

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = getenv('CAPELL_LANG_SPELLING_ROOT') ?: dirname(__DIR__);
$directories = [
    ...glob($root . '/packages/*/resources/lang/en', GLOB_ONLYDIR) ?: [],
    ...glob($root . '/resources/lang/en', GLOB_ONLYDIR) ?: [],
    ...glob($root . '/lang/en', GLOB_ONLYDIR) ?: [],
];
$spellings = [
    'colour' => 'color(?:s|ed|ing|ful|less)?',
    'organisation' => 'organization(?:s|al|ally)?',
    'optimise' => 'optimiz(?:e[ds]?|ers?|ing|ations?)',
    'customise' => 'customiz(?:e[ds]?|ers?|ing|ations?)',
    'catalogue' => 'catalog(?:s|ed|ing)?',
    'behaviour' => 'behavior(?:s|al|ally)?',
    'centre' => 'center(?:s|ed|ing)?',
    'cancelled' => 'cancel(?:ed|ing)',
    // British English still uses "licensed" and "licensing" as verbs.
    'licence' => 'licenses?',
];
$productNames = ['Privacy Center'];
$parser = new ParserFactory()->createForNewestSupportedVersion();
$finder = new NodeFinder;
$failures = [];
$filesChecked = 0;

foreach ($directories as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo) {
            continue;
        }

        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $nodes = $parser->parse((string) file_get_contents($path)) ?? [];
        $filesChecked++;

        // Array keys, comments, PHP identifiers and non-language files are outside this editorial check.
        foreach ($finder->findInstanceOf($nodes, ArrayItem::class) as $item) {
            if (! $item->value instanceof String_) {
                continue;
            }

            $value = str_replace($productNames, '', $item->value->value);
            $value = (string) preg_replace('/:[a-zA-Z_]\w*/', '', $value);

            foreach ($spellings as $preferred => $pattern) {
                if (preg_match_all('/\b(?:' . $pattern . ')\b/i', $value, $matches) === 0) {
                    continue;
                }

                foreach ($matches[0] as $match) {
                    $failures[] = sprintf('%s:%d: "%s" must use British spelling (%s).', substr($path, strlen($root) + 1), $item->value->getStartLine(), $match, $preferred);
                }
            }
        }
    }
}

if ($filesChecked === 0) {
    fwrite(STDERR, "No English language files found.\n");
    exit(2);
}

if ($failures !== []) {
    sort($failures);
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf('British spelling verified in values across %d English language files.%s', $filesChecked, PHP_EOL));
