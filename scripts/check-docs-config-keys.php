<?php

declare(strict_types=1);

$configuredRepositoryRoot = getenv('CAPELL_DOCS_CONFIG_ROOT') ?: dirname(__DIR__);
$repositoryRoot = realpath($configuredRepositoryRoot);

if ($repositoryRoot === false) {
    fwrite(STDERR, sprintf('Missing repository root: %s%s', $configuredRepositoryRoot, PHP_EOL));

    exit(1);
}

$classificationPath = $repositoryRoot . '/scripts/docs-config-key-classifications.php';
$classifications = is_file($classificationPath) ? require $classificationPath : [];

if (! is_array($classifications)) {
    fwrite(STDERR, $classificationPath . ' must return an array.
');

    exit(1);
}

$documentation = '';

foreach (collectFilesByExtension($repositoryRoot . '/docs', 'md') as $markdownFile) {
    $contents = file_get_contents($markdownFile);

    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$markdownFile}.\n");

        exit(1);
    }

    $documentation .= "\n" . $contents;
}

$readmePath = $repositoryRoot . '/README.md';

if (is_file($readmePath)) {
    $readmeContents = file_get_contents($readmePath);

    if ($readmeContents === false) {
        fwrite(STDERR, "Unable to read {$readmePath}.\n");

        exit(1);
    }

    $documentation .= "\n" . $readmeContents;
}

$configKeys = [];

foreach (collectPublicConfigFiles($repositoryRoot . '/packages') as $configFile) {
    $contents = file_get_contents($configFile);

    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$configFile}.\n");

        exit(1);
    }

    $packageName = basename(dirname($configFile, 2));
    $configName = pathinfo($configFile, PATHINFO_FILENAME);

    foreach (extractConfigLeaves($contents, $configName) as $configKey => $environmentVariables) {
        $configKeys[$configKey] = [
            'environmentVariables' => $environmentVariables,
            'source' => substr($configFile, strlen($repositoryRoot) + 1),
            'package' => $packageName,
        ];
    }
}

ksort($configKeys);

$failures = [];
$documentedCount = 0;
$classifiedCount = 0;

foreach ($configKeys as $configKey => $metadata) {
    $documentedByPath = configPathOrAncestorIsDocumented($documentation, $configKey);
    $documentedByEnvironment = array_any(
        $metadata['environmentVariables'],
        static fn (string $variable): bool => containsDocumentationToken($documentation, $variable),
    );

    if ($documentedByPath || $documentedByEnvironment) {
        $documentedCount++;

        if (isset($classifications[$configKey])) {
            $failures[] = $configKey . ': is documented but still has a classification; remove the stale classification.';
        }

        continue;
    }

    $reason = $classifications[$configKey] ?? null;

    if (! is_string($reason) || trim($reason) === '') {
        $failures[] = sprintf('%s: undocumented public config leaf from %s has no explicit classification.', $configKey, $metadata['source']);

        continue;
    }

    $classifiedCount++;
}

foreach (array_keys($classifications) as $classifiedKey) {
    if (! is_string($classifiedKey) || ! array_key_exists($classifiedKey, $configKeys)) {
        $failures[] = $classifiedKey . ': classification does not match a current public config leaf.';
    }
}

if ($failures !== []) {
    sort($failures);

    fwrite(STDERR, "Config-key documentation coverage failed:\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, sprintf('- %s%s', $failure, PHP_EOL));
    }

    fwrite(STDERR, "\nDocument the full config path or backing environment variable, or add a narrow reason to scripts/docs-config-key-classifications.php.\n");

    exit(2);
}

$totalCount = count($configKeys);

echo "{$totalCount} public config leaves covered: {$documentedCount} documented, {$classifiedCount} explicitly classified.\n";

exit(0);

/**
 * @return list<string>
 */
function collectPublicConfigFiles(string $packagesDirectory): array
{
    $configFiles = glob($packagesDirectory . '/*/config/*.php');

    if ($configFiles === false) {
        return [];
    }

    sort($configFiles);

    return $configFiles;
}

/**
 * @return array<string, list<string>>
 */
function extractConfigLeaves(string $contents, string $configName): array
{
    $tokens = token_get_all($contents);
    $tokenCount = count($tokens);
    $assignedEnvironmentVariables = extractAssignedEnvironmentVariables($tokens);

    for ($index = 0; $index < $tokenCount; $index++) {
        if (is_array($tokens[$index]) && $tokens[$index][0] === T_RETURN) {
            $index = nextSignificantTokenIndex($tokens, $index + 1);

            if (($tokens[$index] ?? null) === '[') {
                return parseConfigArray($tokens, $index, $configName, $assignedEnvironmentVariables);
            }
        }
    }

    return [];
}

/**
 * @param  list<array{int, string, int}|string>  $tokens
 * @param  array<string, list<string>>  $assignedEnvironmentVariables
 * @return array<string, list<string>>
 */
function parseConfigArray(array $tokens, int &$index, string $prefix, array $assignedEnvironmentVariables): array
{
    $leaves = [];
    $index++;
    $tokenCount = count($tokens);

    while ($index < $tokenCount) {
        $index = nextSignificantTokenIndex($tokens, $index);
        $token = $tokens[$index] ?? null;

        if ($token === ']') {
            $index++;

            return $leaves;
        }

        if ($token === ',') {
            $index++;

            continue;
        }

        $keyIndex = $index;
        $arrowIndex = nextSignificantTokenIndex($tokens, $keyIndex + 1);

        $arrowToken = $tokens[$arrowIndex] ?? null;

        if (
            ! is_array($token)
            || $token[0] !== T_CONSTANT_ENCAPSED_STRING
            || ! is_array($arrowToken)
            || $arrowToken[0] !== T_DOUBLE_ARROW
        ) {
            skipArrayValue($tokens, $index);

            continue;
        }

        $key = decodePhpStringLiteral($token[1]);
        $configKey = $prefix . '.' . $key;
        $index = nextSignificantTokenIndex($tokens, $arrowIndex + 1);

        if (($tokens[$index] ?? null) === '[') {
            $nestedLeaves = parseConfigArray($tokens, $index, $configKey, $assignedEnvironmentVariables);

            if ($nestedLeaves === []) {
                $leaves[$configKey] = [];
            } else {
                $leaves += $nestedLeaves;
            }

            continue;
        }

        $valueTokens = collectArrayValueTokens($tokens, $index);
        $environmentVariables = extractEnvironmentVariables($valueTokens);

        foreach ($valueTokens as $valueToken) {
            if (is_array($valueToken) && $valueToken[0] === T_VARIABLE) {
                $environmentVariables = [
                    ...$environmentVariables,
                    ...($assignedEnvironmentVariables[$valueToken[1]] ?? []),
                ];
            }
        }

        $leaves[$configKey] = array_values(array_unique($environmentVariables));
    }

    return $leaves;
}

/**
 * @param  list<array{int, string, int}|string>  $tokens
 */
function skipArrayValue(array $tokens, int &$index): void
{
    collectArrayValueTokens($tokens, $index);
}

/**
 * @param  list<array{int, string, int}|string>  $tokens
 * @return list<array{int, string, int}|string>
 */
function collectArrayValueTokens(array $tokens, int &$index): array
{
    $valueTokens = [];
    $depth = 0;
    $tokenCount = count($tokens);

    while ($index < $tokenCount) {
        $token = $tokens[$index];

        if ($depth === 0 && ($token === ',' || $token === ']')) {
            if ($token === ',') {
                $index++;
            }

            break;
        }

        if (in_array($token, ['[', '(', '{'], true)) {
            $depth++;
        } elseif (in_array($token, [']', ')', '}'], true)) {
            $depth--;
        }

        $valueTokens[] = $token;
        $index++;
    }

    return $valueTokens;
}

/**
 * @param  list<array{int, string, int}|string>  $tokens
 */
function nextSignificantTokenIndex(array $tokens, int $index): int
{
    $tokenCount = count($tokens);

    while ($index < $tokenCount) {
        $token = $tokens[$index];

        if (! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $index;
        }

        $index++;
    }

    return $index;
}

function decodePhpStringLiteral(string $literal): string
{
    $quote = $literal[0];
    $value = substr($literal, 1, -1);

    return $quote === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $value) : stripcslashes($value);
}

/**
 * @param  list<array{int, string, int}|string>  $tokens
 * @return list<string>
 */
function extractEnvironmentVariables(array $tokens): array
{
    $variables = [];
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; $index++) {
        $token = $tokens[$index];
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] !== T_STRING) {
            continue;
        }

        if (strtolower($token[1]) !== 'env') {
            continue;
        }

        $openingParenthesis = nextSignificantTokenIndex($tokens, $index + 1);
        $variableIndex = nextSignificantTokenIndex($tokens, $openingParenthesis + 1);
        $variableToken = $tokens[$variableIndex] ?? null;

        if (($tokens[$openingParenthesis] ?? null) === '(' && is_array($variableToken) && $variableToken[0] === T_CONSTANT_ENCAPSED_STRING) {
            $variables[] = decodePhpStringLiteral($variableToken[1]);
        }
    }

    return array_values(array_unique($variables));
}

/**
 * @param  list<array{int, string, int}|string>  $tokens
 * @return array<string, list<string>>
 */
function extractAssignedEnvironmentVariables(array $tokens): array
{
    $assignments = [];
    $tokenCount = count($tokens);

    for ($index = 0; $index < $tokenCount; $index++) {
        $variableToken = $tokens[$index];
        if (! is_array($variableToken)) {
            continue;
        }

        if ($variableToken[0] !== T_VARIABLE) {
            continue;
        }

        $equalsIndex = nextSignificantTokenIndex($tokens, $index + 1);

        if (($tokens[$equalsIndex] ?? null) !== '=') {
            continue;
        }

        $valueTokens = [];
        $valueIndex = $equalsIndex + 1;

        while ($valueIndex < $tokenCount && ($tokens[$valueIndex] ?? null) !== ';') {
            $valueTokens[] = $tokens[$valueIndex];
            $valueIndex++;
        }

        $environmentVariables = extractEnvironmentVariables($valueTokens);

        foreach ($valueTokens as $valueToken) {
            if (is_array($valueToken) && $valueToken[0] === T_VARIABLE) {
                $environmentVariables = [
                    ...$environmentVariables,
                    ...($assignments[$valueToken[1]] ?? []),
                ];
            }
        }

        if ($environmentVariables !== []) {
            $assignments[$variableToken[1]] = array_values(array_unique($environmentVariables));
        }
    }

    return $assignments;
}

function containsDocumentationToken(string $documentation, string $token): bool
{
    return preg_match(
        '/(?<![A-Za-z0-9_.-])' . preg_quote($token, '/') . '(?![A-Za-z0-9_.-])/',
        $documentation,
    ) === 1;
}

function configPathOrAncestorIsDocumented(string $documentation, string $configKey): bool
{
    $segments = explode('.', $configKey);

    while (count($segments) > 1) {
        $path = implode('.', $segments);

        if (
            containsDocumentationToken($documentation, $path)
            || containsDocumentationToken($documentation, $path . '.*')
        ) {
            return true;
        }

        array_pop($segments);
    }

    return false;
}

/**
 * @return list<string>
 */
function collectFilesByExtension(string $directory, string $extension): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $collectedFiles = [];
    $directoryIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($directoryIterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower((string) $fileInfo->getExtension()) === $extension) {
            $collectedFiles[] = $fileInfo->getRealPath();
        }
    }

    sort($collectedFiles);

    return $collectedFiles;
}
