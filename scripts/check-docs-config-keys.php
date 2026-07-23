<?php

declare(strict_types=1);
use Illuminate\Foundation\Application;

/*
 * Guards against config keys silently going undocumented.
 *
 * The hosting audit found ~74 operator-facing config keys with no env var and no
 * documentation — invisible unless you read the source. `check-docs-env-vars.php`
 * covers env-backed keys; this covers the rest.
 *
 * A config key must be one of:
 *   - env-backed  — its value calls env(), so it is the env-var guard's concern;
 *   - structural  — palette values, icon/asset wiring, component maps: data, not
 *                   operator config, and never documented key-by-key;
 *   - documented  — its dotted path appears verbatim somewhere in docs/;
 *   - baselined   — a known, pre-existing gap listed below.
 *
 * Anything else fails the build. The baseline is the current debt; it is meant to
 * shrink, never grow. Adding a new config key forces a choice: document it, or add
 * a justified line here.
 */

$repositoryRoot = realpath(getenv('CAPELL_DOCS_CONFIG_ROOT') ?: dirname(__DIR__));

if ($repositoryRoot === false) {
    fwrite(STDERR, "Missing repository root.\n");

    exit(1);
}

require $repositoryRoot . '/vendor/autoload.php';

// A bare container so env()/base_path()/storage_path() resolve inside config files.
new Application($repositoryRoot);

/*
 * Structural sub-trees: pure data or framework wiring, not operator-tunable config.
 * Each prefix needs a reason. A dotted path starting with any of these is skipped.
 */
$structuralPrefixes = [
    'capell.default_colors.' => 'theme colour palette values',
    'capell.default_pages' => 'seed page list',
    'capell.media.crop_presets.' => 'named image crop dimensions',
    'capell.runtime.auth_paths' => 'auth path patterns, list data',
    'capell-admin.social_types.' => 'social-link field definitions per network',
    'capell-admin.assets.' => 'Filament asset resource icon/colour/model wiring',
    'capell-admin.resources.' => 'Filament resource icon/badge wiring',
    'capell-admin.icon.' => 'Filament navigation icon wiring',
    'capell-admin.shortcuts' => 'admin keyboard shortcut map',
    'capell-frontend.livewire_components' => 'public component class map',
    'capell-frontend.blade_components' => 'public component class map',
    'capell-frontend.cache_vary_headers' => 'static header list',
];

/*
 * Known undocumented operator-facing keys carried over from before this guard
 * existed. This list must only shrink. Do not add to it to make a new key pass —
 * document the key instead.
 */
$baseline = [
    'backup.media_disks',
    'capell-admin.security_headers.headers.Permissions-Policy',
    'capell-admin.security_headers.headers.Referrer-Policy',
    'capell-admin.security_headers.headers.X-Content-Type-Options',
    'capell-admin.security_headers.headers.X-Frame-Options',
    'capell-admin.upgrades.api_timeout_seconds',
    'capell-admin.upgrades.danger_threshold',
    'capell-admin.upgrades.notifications.emails',
    'capell-admin.user_resource.default_schema_type',
    'capell-admin.user_resource.role_schema_types.super_admin',
    'capell-frontend.foundation_theme',
    'capell-frontend.tailwind.imports',
    'capell-frontend.tailwind.plugins',
    'capell-installer.database_table_cache.key',
    'capell-installer.default_packages',
    'capell-installer.installation_state_cache.host',
    'capell-installer.installation_state_cache.key',
    'capell.diagnostics.allowed_roots',
    'capell.lockdown.break_glass_emails',
    'capell.lockdown.break_glass_user_ids',
    'capell.plugins',
    'capell.publishing-studio.notifications.channels',
    'capell.publishing-studio.notifications.recipients.abandoned',
    'capell.publishing-studio.notifications.recipients.approved',
    'capell.publishing-studio.notifications.recipients.changes_requested',
    'capell.publishing-studio.notifications.recipients.published',
    'capell.publishing-studio.notifications.recipients.rejected',
    'capell.publishing-studio.notifications.recipients.submitted',
    'capell.publishing-studio.release_windows.bypass_permission',
    'capell.publishing-studio.release_windows.windows',
    'capell.publishing-studio.review_policy.content_types',
    'capell.publishing-studio.review_policy.default.minimum',
    'capell.sitemap.directory',
];

$baselineLookup = array_fill_keys($baseline, true);

/**
 * Flatten a config array to dotted leaf keys, recording whether each leaf's source
 * value calls env(). Numeric (list) keys are not config keys and are skipped.
 *
 * @param  array<int|string, mixed>  $configArray
 * @return array<string, bool> dotted key => env-backed
 */
function flattenConfigKeys(array $configArray, string $prefix, string $source): array
{
    $flattened = [];

    foreach ($configArray as $key => $value) {
        if (is_int($key)) {
            continue;
        }

        $path = $prefix === '' ? $key : "{$prefix}.{$key}";

        if (is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            $flattened += flattenConfigKeys($value, $path, $source);

            continue;
        }

        $quotedSegment = preg_quote((string) $key, '/');
        $flattened[$path] = (bool) preg_match("/'{$quotedSegment}'\\s*=>[^\\n]*env\\(/", $source);
    }

    return $flattened;
}

$configKeys = [];

foreach (glob($repositoryRoot . '/packages/*/config/*.php') as $configFile) {
    $configArray = require $configFile;
    $source = file_get_contents($configFile);

    if (is_array($configArray) && $source !== false) {
        $configKeys += flattenConfigKeys($configArray, basename($configFile, '.php'), $source);
    }
}

ksort($configKeys);

$documentation = '';

foreach (collectMarkdownFiles($repositoryRoot . '/docs') as $markdownFile) {
    $documentation .= file_get_contents($markdownFile) ?: '';
}

$failures = [];
$staleBaseline = [];
$checkedCount = 0;

foreach ($configKeys as $dottedKey => $isEnvBacked) {
    if ($isEnvBacked || isStructural($dottedKey, $structuralPrefixes)) {
        continue;
    }

    $checkedCount++;
    $documented = str_contains($documentation, $dottedKey);
    $baselined = isset($baselineLookup[$dottedKey]);

    if ($documented && $baselined) {
        $staleBaseline[] = $dottedKey;

        continue;
    }

    if ($documented || $baselined) {
        continue;
    }

    $failures[] = "{$dottedKey}: config key is neither documented (its dotted path is absent from docs/) nor baselined.";
}

if ($staleBaseline !== []) {
    sort($staleBaseline);

    fwrite(STDERR, "Baseline entries that are now documented — remove them from \$baseline in scripts/check-docs-config-keys.php:\n");

    foreach ($staleBaseline as $staleKey) {
        fwrite(STDERR, "- {$staleKey}\n");
    }

    exit(2);
}

if ($failures !== []) {
    sort($failures);

    fwrite(STDERR, "Undocumented config key(s):\n");

    foreach ($failures as $failure) {
        fwrite(STDERR, "- {$failure}\n");
    }

    fwrite(STDERR, "\nDocument the key in docs/ (its dotted path must appear), mark it structural, or add a justified baseline line in scripts/check-docs-config-keys.php.\n");

    exit(2);
}

echo "{$checkedCount} operator-facing config keys verified (documented or baselined).\n";

exit(0);

/**
 * @param  array<string, string>  $structuralPrefixes
 */
function isStructural(string $dottedKey, array $structuralPrefixes): bool
{
    foreach (array_keys($structuralPrefixes) as $prefix) {
        if ($dottedKey === rtrim($prefix, '.') || str_starts_with($dottedKey, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function collectMarkdownFiles(string $directory): array
{
    $markdownFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'md') {
            $realPath = $fileInfo->getRealPath();

            if ($realPath !== false) {
                $markdownFiles[] = $realPath;
            }
        }
    }

    sort($markdownFiles);

    return $markdownFiles;
}
