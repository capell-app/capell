<?php

declare(strict_types=1);

/**
 * HTTP evidence for scripts/upgrade-path.sh: which pages a site serves, and whether they still
 * render after an upgrade.
 *
 * Usage:
 *   pages.php crawl <base-url> <pages.json> <admin-path> <seed-paths.txt>
 *   pages.php record <base-url> <pages.json> <paths.txt> <cookie-jar>
 *   pages.php recheck <base-url> <pages.json> <allowed-changes.json> public
 *   pages.php recheck <base-url> <pages.json> <allowed-changes.json> admin <paths.txt> <cookie-jar>
 *
 * `crawl` starts from the home page and the seed paths, follows same-site links (never into the
 * admin) and records each page's status and title. `record` does the same for a fixed list of
 * admin paths, requested with the administrator's cookie jar.
 *
 * `recheck` requests every recorded page again. One that rendered must still return a complete
 * HTML page; a public page must also keep its title, which comes from site content. One that
 * did not render (a designed 404 or a refused admin page, say) must not have become a server
 * error. For the admin, pages that exist only after the upgrade are checked the same way. A page
 * removed or retitled on purpose is named, with its reason, under "pages" in
 * allowed-changes.json and is then not rechecked.
 */
const MAXIMUM_PAGES = 200;

/**
 * @return array{status: int, type: string, body: string, url: string}
 */
function fetch(string $url, ?string $cookieJar = null): array
{
    $handle = curl_init($url);

    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: text/html'],
    ]);

    if ($cookieJar !== null) {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieJar);
    }

    $body = curl_exec($handle);

    return [
        'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
        'type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
        'body' => is_string($body) ? $body : '',
        'url' => curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
    ];
}

function title(string $html): string
{
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $match) !== 1) {
        return '';
    }

    return trim((string) preg_replace('~\s+~', ' ', html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5)));
}

/**
 * Why a response is not a complete rendered HTML page, or null when it is one.
 *
 * @param  array{status: int, type: string, body: string, url: string}  $response
 */
function renderingProblem(array $response): ?string
{
    if ($response['status'] !== 200) {
        return sprintf('status %d', $response['status']);
    }

    if (! str_starts_with(strtolower($response['type']), 'text/html')) {
        return sprintf('content type %s', $response['type'] === '' ? '(none)' : $response['type']);
    }

    if (stripos($response['body'], '</html>') === false) {
        return 'the HTML document is incomplete';
    }

    return null;
}

/**
 * Same-site page paths linked from an HTML document.
 *
 * @return list<string>
 */
function linkedPaths(string $html, string $baseUrl, string $adminPath): array
{
    preg_match_all('~<a\s[^>]*href=(["\'])(.*?)\1~is', $html, $matches);
    $paths = [];

    foreach ($matches[2] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5);

        if (str_starts_with($href, $baseUrl)) {
            $href = substr($href, strlen($baseUrl));
        }

        $path = '/' . ltrim((string) strtok($href, '?#'), '/');
        if (! str_starts_with($href, '/')) {
            continue;
        }

        if (str_starts_with($href, '//')) {
            continue;
        }

        if ($path === $adminPath) {
            continue;
        }

        if (str_starts_with($path, $adminPath . '/')) {
            continue;
        }

        if (preg_match('~\.[a-z0-9]{2,5}$~i', $path) === 1) {
            continue;
        }

        $paths[] = $path;
    }

    return array_values(array_unique($paths));
}

/**
 * @param  list<string>  $seedPaths
 * @return array<string, array{status: int, title: string, problem: string|null}>
 */
function crawl(string $baseUrl, string $adminPath, array $seedPaths): array
{
    $queue = ['/', ...$seedPaths];
    $pages = [];

    while ($queue !== [] && count($pages) < MAXIMUM_PAGES) {
        $path = array_shift($queue);

        if (array_key_exists($path, $pages)) {
            continue;
        }

        $response = fetch($baseUrl . $path);
        $pages[$path] = [
            'status' => $response['status'],
            'title' => title($response['body']),
            'problem' => renderingProblem($response),
        ];

        if ($pages[$path]['problem'] === null) {
            array_push($queue, ...linkedPaths($response['body'], $baseUrl, $adminPath));
        }
    }

    ksort($pages);

    return $pages;
}

/**
 * @param  list<string>  $paths
 * @return array<string, array{status: int, title: string, problem: string|null}>
 */
function record(string $baseUrl, array $paths, string $cookieJar): array
{
    $pages = [];

    foreach ($paths as $path) {
        $response = fetch($baseUrl . $path, $cookieJar);
        $pages[$path] = [
            'status' => $response['status'],
            'title' => title($response['body']),
            'problem' => renderingProblem($response) ?? signInRedirect($response),
        ];
    }

    return $pages;
}

/**
 * @param  array{status: int, type: string, body: string, url: string}  $response
 */
function signInRedirect(array $response): ?string
{
    return preg_match('~/login/?$~', (string) parse_url($response['url'], PHP_URL_PATH)) === 1
        ? 'redirected to the sign-in page'
        : null;
}

/**
 * @param  array<string, array{status: int, title: string, problem: string|null}>  $before
 * @param  list<string>  $pathsAfter  Paths that exist after the upgrade, whether or not they did before.
 * @param  array<string, string>  $allowed  Paths changed on purpose, each with its reason.
 * @return list<string>
 */
function recheck(string $baseUrl, array $before, array $pathsAfter, array $allowed, ?string $cookieJar, bool $compareTitles): array
{
    $failures = [];

    foreach (array_unique([...array_keys($before), ...$pathsAfter]) as $path) {
        if (array_key_exists($path, $allowed)) {
            printf("skip %s (allowed: %s)\n", $path, $allowed[$path]);

            continue;
        }

        $response = fetch($baseUrl . $path, $cookieJar);
        $recorded = $before[$path] ?? null;
        $problem = null;

        if ($recorded !== null && $recorded['problem'] === null) {
            $problem = renderingProblem($response) ?? ($cookieJar === null ? null : signInRedirect($response));

            if ($problem === null && $compareTitles && title($response['body']) !== $recorded['title']) {
                $problem = sprintf('title changed from "%s" to "%s"', $recorded['title'], title($response['body']));
            }
        } elseif ($response['status'] >= 500 || $response['status'] === 0) {
            $problem = $recorded === null
                ? sprintf('status %d on a page the upgrade added', $response['status'])
                : sprintf('status %d, was %d before the upgrade', $response['status'], $recorded['status']);
        }

        $note = match (true) {
            $problem !== null => ' (' . $problem . ')',
            $recorded === null => sprintf(' (added by the upgrade, status %d)', $response['status']),
            $recorded['problem'] !== null => sprintf(' (status %d, as before)', $response['status']),
            default => '',
        };
        printf("%-4s %s%s\n", $problem === null ? 'ok' : 'FAIL', $path, $note);

        if ($problem !== null) {
            $failures[] = $path;
        }
    }

    return $failures;
}

/**
 * @return list<string>
 */
function readPaths(string $file): array
{
    $paths = array_filter(array_map(trim(...), (array) file($file)));

    return array_values(array_map(static fn (string $path): string => '/' . ltrim($path, '/'), $paths));
}

/**
 * @param  array<string, array{status: int, title: string, problem: string|null}>  $pages
 */
function writePages(string $file, array $pages): int
{
    file_put_contents($file, json_encode($pages, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

    foreach ($pages as $path => $page) {
        printf("%-4s %s%s\n", $page['problem'] === null ? 'page' : 'none', $path, $page['problem'] === null ? ' "' . $page['title'] . '"' : ' (' . $page['problem'] . ')');
    }

    return count(array_filter($pages, static fn (array $page): bool => $page['problem'] === null));
}

/**
 * @param  list<string>  $arguments
 */
function main(array $arguments): int
{
    $command = $arguments[1] ?? '';
    $baseUrl = rtrim($arguments[2] ?? '', '/');

    if ($command === 'crawl' && count($arguments) === 6) {
        $pages = crawl($baseUrl, '/' . trim($arguments[4], '/'), readPaths($arguments[5]));
        printf("%d of %d public addresses render a page\n", writePages($arguments[3], $pages), count($pages));

        return 0;
    }

    if ($command === 'record' && count($arguments) === 6) {
        $pages = record($baseUrl, readPaths($arguments[4]), $arguments[5]);
        printf("%d of %d admin pages render\n", writePages($arguments[3], $pages), count($pages));

        return 0;
    }

    $area = $arguments[5] ?? '';

    if ($command === 'recheck' && (($area === 'public' && count($arguments) === 6) || ($area === 'admin' && count($arguments) === 8))) {
        /** @var array<string, array{status: int, title: string, problem: string|null}> $before */
        $before = json_decode((string) file_get_contents($arguments[3]), true, flags: JSON_THROW_ON_ERROR);
        $allowed = json_decode((string) file_get_contents($arguments[4]), true, flags: JSON_THROW_ON_ERROR)['pages'] ?? [];
        $failures = $area === 'public'
            ? recheck($baseUrl, $before, [], $allowed, null, compareTitles: true)
            : recheck($baseUrl, $before, readPaths($arguments[6]), $allowed, $arguments[7], compareTitles: false);
        printf("%d %s page(s) no longer answer as they did before the upgrade\n", count($failures), $area);

        return $failures === [] ? 0 : 1;
    }

    fwrite(STDERR, "Usage: pages.php crawl|record|recheck ... (see the file header)\n");

    return 2;
}

exit(main($argv));
