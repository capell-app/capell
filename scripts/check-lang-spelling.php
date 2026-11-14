<?php

declare(strict_types=1);

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
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
    // Nouns use licence/licences; reviewed verb phrases are exempted below.
    'licence' => 'licenses?',
];
// Exact, reviewed phrases only: adding an exception must preserve checks on surrounding prose.
$reviewedPhrases = [
    'Privacy Center', // Product name.
    'Organization for Security and Co-operation in Europe', // Official proper name.
    'License this software', // British imperative verb.
    'she licenses it', // British third-person verb.
];
$reviewedPattern = '/\b(?:' . implode('|', array_map(static fn (string $phrase): string => preg_quote($phrase, '/'), $reviewedPhrases)) . ')\b/u';
// Hyphenated Capell package slugs and their class-style names are developer identifiers; an editor-facing value
// that contains one is a replacement mistake, not copy. Single-word slugs are ordinary English and stay out.
$packageSlugs = [
    'access-gate', 'agent-bridge', 'agent-delivery', 'ai-creator', 'ai-orchestrator', 'ai-spend-caps', 'automation-studio',
    'block-library', 'campaign-studio', 'content-sections', 'customer-portal', 'dashboard-reports', 'demo-kit',
    'discovery-foundation', 'document-lifecycle', 'email-studio', 'exception-reports', 'extension-cookbook', 'filament-peek',
    'form-builder', 'frontend-authoring', 'frontend-optimizer', 'ga4-reports', 'html-cache', 'inertia-react-adapter',
    'inertia-vue-adapter', 'knowledge-base', 'layout-builder', 'live-chat', 'login-audit', 'media-ai',
    'migration-assistant', 'password-policy', 'privacy-center', 'public-actions', 'publishing-studio', 'record-switcher',
    'security-analyser', 'seo-suite', 'shopify-commerce', 'site-discovery', 'site-monitor', 'site-stats', 'smart-404',
    'social-feeds', 'statamic-importer', 'structured-content-library', 'translation-manager', 'url-manager',
    'voyager-importer', 'welcome-tour', 'wordpress-importer',
];
$packageSlugPattern = '/(?<![\w-])(?:' . implode('|', array_map(
    static fn (string $slug): string => preg_quote($slug, '/') . '|' . preg_quote(str_replace(' ', '', ucwords(str_replace('-', ' ', $slug))), '/'),
    $packageSlugs,
)) . ')(?![\w-])/';
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

        // Never execute language PHP to evaluate expressions. Unsupported values fail closed.
        foreach ($finder->findInstanceOf($nodes, Return_::class) as $return) {
            if (! $return->expr instanceof Array_) {
                $failures[] = sprintf('%s:%d: Unsupported translation expression; return a literal array.', substr($path, strlen($root) + 1), $return->getStartLine());
            }
        }

        // Array keys, comments, PHP identifiers and non-language files are outside this editorial check.
        foreach ($finder->findInstanceOf($nodes, ArrayItem::class) as $item) {
            if (! $item->unpack && $item->value instanceof Array_) {
                continue;
            }

            $value = $item->unpack ? null : constantTranslationString($item->value);
            if ($value === null) {
                $failures[] = sprintf('%s:%d: Unsupported translation expression; use literal strings, constant string concatenations or nested arrays.', substr($path, strlen($root) + 1), $item->value->getStartLine());

                continue;
            }

            // Code examples are deliberately technical spelling; prose outside them is still checked.
            $value = (string) preg_replace('/<(code|pre)\b[^>]*>.*?<\/\1\s*>|(`+)[^`]*?\2/is', '', $value);
            $value = (string) preg_replace($reviewedPattern, '', $value);
            $value = (string) preg_replace('/:[a-zA-Z_]\w*/', '', $value);

            // Composer names legitimately carry a slug after capell-app/.
            $withoutPackageNames = (string) preg_replace('#capell-app/[a-z0-9-]+#', '', $value);

            if (preg_match_all($packageSlugPattern, $withoutPackageNames, $slugMatches) > 0) {
                foreach ($slugMatches[0] as $match) {
                    $failures[] = sprintf('%s:%d: "%s" is a package name; write what the person sees instead.', substr($path, strlen($root) + 1), $item->value->getStartLine(), $match);
                }
            }

            // Plurals use trans_choice ranges, and a dash is written as a full stop, colon or comma.
            if (preg_match('/[a-z]\(s\)/i', $value) === 1) {
                $failures[] = sprintf('%s:%d: Use a trans_choice plural instead of "(s)".', substr($path, strlen($root) + 1), $item->value->getStartLine());
            }

            if (str_contains($value, "\u{2014}")) {
                $failures[] = sprintf('%s:%d: Replace the em dash with a full stop, colon or comma.', substr($path, strlen($root) + 1), $item->value->getStartLine());
            }

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

/** Resolve only the supported constant string grammar, without executing translation files. */
function constantTranslationString(Expr $expression): ?string
{
    if ($expression instanceof String_) {
        return $expression->value;
    }

    if ($expression instanceof Concat) {
        $left = constantTranslationString($expression->left);
        $right = constantTranslationString($expression->right);

        return $left !== null && $right !== null ? $left . $right : null;
    }

    return null;
}
