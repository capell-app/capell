<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Support\Http\OutboundHttp;
use Illuminate\Support\Facades\Http;
use PhpToken;
use Symfony\Component\Finder\Finder;

/**
 * Finds the classes in a package's `src` that open outbound connections, from their tokens rather than their text.
 *
 * A class is an outbound client when any name it imports or writes falls under a known HTTP client namespace or
 * class (Laravel's HTTP client, Guzzle, PSR-18, Symfony HttpClient, Saloon, Prism, Core's OutboundHttp), when it
 * calls `curl_*` functions, when it opens a socket or an http stream context, when a stream function
 * (`file_get_contents`, `fopen`, ...) reads an http(s) URL, or when it drives a Playwright browser process.
 *
 * A stream function whose target is neither a URL nor evidently local cannot be classified. The line before such a
 * call must declare it reviewed with `// capell-security-scan: local-stream <reason>` or
 * `// capell-security-scan: remote-stream <reason>`; otherwise it is reported as a problem.
 *
 * An SDK that wraps its own transport is not detected; a package declares it in
 * `security.externalHttpClients.manualClients`.
 */
final class OutboundHttpSourceScanner
{
    /** @var list<string> */
    public const array HTTP_NAMESPACE_PREFIXES = [
        'GuzzleHttp\\',
        'Illuminate\\Http\\Client\\',
        'Psr\\Http\\Client\\',
        'Symfony\\Component\\HttpClient\\',
        'Symfony\\Contracts\\HttpClient\\',
        'Http\\Client\\',
        'Saloon\\',
    ];

    /** Single classes that make requests although their namespace holds unrelated code. */
    private const array HTTP_CLASSES = [
        Http::class,
        OutboundHttp::class,
    ];

    /** Functions that open a stream on whatever their first argument names, which may be an http(s) URL. */
    private const array STREAM_FUNCTIONS = ['file_get_contents', 'fopen', 'file', 'readfile', 'copy', 'get_headers', 'getimagesize', 'get_meta_tags', 'simplexml_load_file', 'parse_ini_file', 'md5_file', 'sha1_file', 'hash_file'];

    /** Facade calls that configure or fake the client rather than build a request (compared in lower case). */
    private const array FACADE_CONFIGURATION_METHODS = ['fake', 'fakesequence', 'macro', 'mixin', 'preventstrayrequests', 'allowstrayrequests', 'globalmiddleware', 'globalrequestmiddleware', 'globalresponsemiddleware', 'globaloptions', 'recorded', 'assertsent', 'assertnotsent', 'assertnothingsent', 'assertsentcount', 'assertsequencesareempty', 'response', 'sequence', 'failedconnection', 'swap'];

    /** Functions that open a network socket whatever their argument. */
    private const array SOCKET_FUNCTIONS = ['fsockopen', 'pfsockopen', 'stream_socket_client'];

    /** Calls whose result is a local path, so a stream argument that starts with one reads a local file. */
    private const array LOCAL_PATH_CALLS = ['base_path', 'storage_path', 'resource_path', 'public_path', 'database_path', 'config_path', 'app_path', 'lang_path', 'dirname', 'sys_get_temp_dir', 'tempnam', 'realpath', 'getcwd'];

    /**
     * The fully qualified names of the classes in `<packageRoot>/src` that open outbound connections, sorted.
     *
     * @return list<string>
     */
    public function clientClasses(string $packageRoot): array
    {
        $files = $this->sourceFiles($packageRoot);
        $definesPrismProvider = false;

        foreach ($files as $contents) {
            if (str_contains($contents, 'class PrismProvider')) {
                $definesPrismProvider = true;

                break;
            }
        }

        $classes = [];

        foreach ($files as $contents) {
            $usesPrismChat = ! $definesPrismProvider && str_contains($contents, 'PrismProvider') && str_contains($contents, '->chat(');

            if (! $this->makesHttpRequests($contents) && ! $this->scanStreams($contents)['http'] && ! $this->drivesBrowserProcess($contents) && ! $usesPrismChat) {
                continue;
            }

            $class = $this->className($contents);

            if ($class !== null) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return array_values(array_unique($classes));
    }

    /**
     * Stream calls that cannot be classified, as `src/<file>:<line>: <message>`.
     *
     * @return list<string>
     */
    public function streamProblems(string $packageRoot): array
    {
        $problems = [];

        foreach ($this->sourceFiles($packageRoot) as $relativePath => $contents) {
            foreach ($this->scanStreams($contents)['problems'] as $problem) {
                $problems[] = sprintf('src/%s:%d: %s', $relativePath, $problem['line'], $problem['message']);
            }
        }

        return $problems;
    }

    /**
     * Requests made through the `Http` facade without an explicit timeout, and Playwright processes without one, as
     * `src/<file>:<line>`. `OutboundHttp::guarded()` always applies its policy's timeouts, so it is not reported.
     *
     * @return list<string>
     */
    public function requestsWithoutTimeouts(string $packageRoot): array
    {
        $failures = [];

        foreach ($this->sourceFiles($packageRoot) as $relativePath => $contents) {
            if ($this->drivesBrowserProcess($contents) && ! str_contains($contents, '->setTimeout(')) {
                preg_match('/new\s+Process\s*\(\s*\[(?:(?!\]\s*[,)]).)*->scriptPath\s*\(/s', $contents, $browser, PREG_OFFSET_CAPTURE);
                $failures[] = sprintf('src/%s:%d', $relativePath, $this->lineAt($contents, (int) ($browser[0][1] ?? 0)));
            }

            foreach ($this->facadeRequestStatements($contents) as $request) {
                if (preg_match('/(?:->|::)timeout\(|[\'"]timeout[\'"]=>/', $request['statement']) !== 1) {
                    $failures[] = sprintf('src/%s:%d', $relativePath, $request['line']);
                }
            }
        }

        return $failures;
    }

    /**
     * Requests built on the raw `Http` facade, as `src/<file>:<line>`, unless the line before declares the target
     * reviewed with `// capell-security-scan: raw-http <reason>`.
     *
     * The raw facade checks nothing about the address it is given, follows redirects and honours proxy settings, so
     * a request to an address from configuration, content or a user belongs on `OutboundHttp::guarded()`. A raw
     * request is right only where the guard would be wrong, such as a fixed provider host, a probe of the
     * application's own (possibly private) address, or a request that must go through an operator's proxy, and the
     * declaration records why.
     *
     * @return list<string>
     */
    public function rawFacadeRequests(string $packageRoot): array
    {
        $failures = [];

        foreach ($this->sourceFiles($packageRoot) as $relativePath => $contents) {
            $lines = explode("\n", $contents);

            foreach ($this->facadeRequestStatements($contents) as $request) {
                if (preg_match('/\/\/\s*capell-security-scan:\s*raw-http\s+\S/', $lines[$request['line'] - 2] ?? '') !== 1) {
                    $failures[] = sprintf('src/%s:%d', $relativePath, $request['line']);
                }
            }
        }

        return $failures;
    }

    public function makesHttpRequests(string $contents): bool
    {
        $tokens = $this->significantTokens($contents);

        $namespace = '';
        $imports = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token->is(T_NAMESPACE) && isset($tokens[$index + 1]) && $tokens[$index + 1]->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$index + 1]->text;

                continue;
            }

            if ($token->is(T_USE)) {
                $next = $tokens[$index + 1] ?? null;
                if (! $next instanceof PhpToken) {
                    continue;
                }

                if ($next->is('(')) {
                    continue;
                }

                $statement = [];

                for ($index++; $index < $count && ! $tokens[$index]->is(';'); $index++) {
                    $statement[] = $tokens[$index];
                }

                foreach ($this->useStatementNames($statement) as [$name, $alias, $kind]) {
                    if ($kind === 'function' ? $this->isHttpFunction($name) : $this->isHttpClientClass($name)) {
                        return true;
                    }

                    if ($kind === 'class') {
                        $imports[strtolower($alias)] = $name;
                    }
                }

                continue;
            }

            $previous = $tokens[$index - 1] ?? null;
            $following = $tokens[$index + 1] ?? null;

            if ($previous instanceof PhpToken && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST])) {
                continue;
            }

            if ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE])) {
                if ($following instanceof PhpToken && $following->is('(') && $token->is(T_NAME_FULLY_QUALIFIED) && $this->isHttpFunction($token->text)) {
                    return true;
                }

                // The global facade aliases, written fully qualified.
                if ($token->is(T_NAME_FULLY_QUALIFIED) && in_array($token->text, ['\Http', '\Prism'], true) && $following instanceof PhpToken && $following->is(T_DOUBLE_COLON)) {
                    return true;
                }

                if ($this->isHttpClientClass($this->resolveClassName($token, $namespace, $imports))) {
                    return true;
                }

                continue;
            }

            if (! $token->is(T_STRING)) {
                continue;
            }

            if ($following instanceof PhpToken && $following->is('(') && $this->isHttpFunction($token->text)) {
                return true;
            }

            // `Http::` and `Prism::` name the global facade aliases only in code without a namespace; in a namespace
            // they resolve to the package's own class unless imported, and an import is judged above.
            if ($namespace === '' && $following instanceof PhpToken && $following->is(T_DOUBLE_COLON) && ! isset($imports[strtolower($token->text)]) && in_array($token->text, ['Http', 'Prism'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Outbound HTTP through PHP streams: a stream function whose first argument is an http(s) URL, a socket function,
     * or `stream_context_create` with an http option, plus the stream calls that cannot be classified.
     *
     * @return array{http: bool, problems: list<array{line: int, message: string}>}
     */
    public function scanStreams(string $contents): array
    {
        $tokens = $this->significantTokens($contents);

        $lines = explode("\n", $contents);
        $count = count($tokens);
        $http = false;
        $problems = [];
        $functionImports = [];

        for ($index = 0; $index < $count; $index++) {
            if (! $tokens[$index]->is(T_USE)) {
                continue;
            }

            if (! ($tokens[$index + 1] ?? null)?->is(T_FUNCTION)) {
                continue;
            }

            for ($cursor = $index + 2; $cursor < $count && ! $tokens[$cursor]->is(';'); $cursor++) {
                if ($tokens[$cursor]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])) {
                    $name = strtolower(ltrim($tokens[$cursor]->text, '\\'));
                    $alias = ($tokens[$cursor + 1] ?? null)?->is(T_AS) === true
                        ? strtolower($tokens[$cursor + 2]->text ?? '')
                        : substr($name, (int) strrpos('\\' . $name, '\\'));
                    $functionImports[$alias] = $name;
                }
            }
        }

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            $previous = $tokens[$index - 1] ?? null;
            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            if (! ($tokens[$index + 1] ?? null)?->is('(')) {
                continue;
            }

            if ($previous instanceof PhpToken && $previous->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST])) {
                continue;
            }

            $name = strtolower(ltrim($token->text, '\\'));
            $name = $functionImports[$name] ?? $name;

            if (in_array($name, self::SOCKET_FUNCTIONS, true)) {
                $http = true;

                continue;
            }

            if ($name === 'stream_context_create') {
                for ($cursor = $index + 2, $depth = 1; $cursor < $count && $depth > 0; $cursor++) {
                    $depth += $tokens[$cursor]->is(['(', '[']) ? 1 : ($tokens[$cursor]->is([')', ']']) ? -1 : 0);

                    if ($tokens[$cursor]->is(T_CONSTANT_ENCAPSED_STRING) && in_array(strtolower(trim($tokens[$cursor]->text, '\'"')), ['http', 'https'], true)) {
                        $http = true;
                    }
                }

                continue;
            }

            if (! in_array($name, self::STREAM_FUNCTIONS, true)) {
                continue;
            }

            $declaration = $this->streamDeclaration($lines, $token->line);
            $target = $this->streamTarget($tokens, $index + 2);

            if ($target === 'dynamic' && $this->streamGuardedAsFile($tokens, $index)) {
                $target = 'local';
            }

            if ($target === 'remote' || $declaration === 'remote') {
                $http = true;
            } elseif ($target === 'dynamic' && $declaration === null) {
                $problems[] = ['line' => $token->line, 'message' => $name . '() opens a target that is neither a URL nor evidently local; declare it with `// capell-security-scan: local-stream <reason>` or `// capell-security-scan: remote-stream <reason>` on the line before.'];
            }
        }

        return ['http' => $http, 'problems' => $problems];
    }

    /** A Playwright script started through a Process fetches remote pages the way an HTTP client does. */
    public function drivesBrowserProcess(string $contents): bool
    {
        return str_contains($contents, 'playwright')
            && preg_match('/new\s+Process\s*\(\s*\[(?:(?!\]\s*[,)]).)*->scriptPath\s*\(/s', $contents) === 1;
    }

    /**
     * The statements that build a request through the `Http` facade, however the facade is named (imported, aliased,
     * fully qualified or the global alias), with the line each starts on. A statement runs to its semicolon, so a
     * request builder kept in a variable is judged where it is built. Facade calls that configure the client rather
     * than build a request are not statements of this kind.
     *
     * @return list<array{line: int, statement: string}>
     */
    private function facadeRequestStatements(string $contents): array
    {
        $tokens = $this->significantTokens($contents);
        $count = count($tokens);
        $namespace = '';
        $imports = [];
        $requests = [];

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token->is(T_NAMESPACE) && isset($tokens[$index + 1]) && $tokens[$index + 1]->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $tokens[$index + 1]->text;

                continue;
            }

            if ($token->is(T_USE) && ! ($tokens[$index + 1] ?? null)?->is('(')) {
                $statement = [];

                for ($index++; $index < $count && ! $tokens[$index]->is(';'); $index++) {
                    $statement[] = $tokens[$index];
                }

                foreach ($this->useStatementNames($statement) as [$name, $alias, $kind]) {
                    if ($kind === 'class') {
                        $imports[strtolower($alias)] = $name;
                    }
                }

                continue;
            }

            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            if (! ($tokens[$index + 1] ?? null)?->is(T_DOUBLE_COLON)) {
                continue;
            }

            if (($tokens[$index - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW]) === true) {
                continue;
            }

            if (! $this->namesHttpFacade($token, $namespace, $imports)) {
                continue;
            }

            if (($tokens[$index + 2] ?? null)?->is(T_CLASS) === true) {
                continue;
            }

            $method = strtolower($tokens[$index + 2]->text ?? '');
            if (in_array($method, self::FACADE_CONFIGURATION_METHODS, true)) {
                continue;
            }

            // Reading the client for a later swap is not a request. Keep
            // chained calls in the scan, where they can act on that client.
            if ($method === 'getfacaderoot'
                && ($tokens[$index + 3] ?? null)?->is('(')
                && ($tokens[$index + 4] ?? null)?->is(')')
                && ($tokens[$index + 5] ?? null)?->is(';')) {
                continue;
            }

            $statement = '';

            for ($cursor = $index, $depth = 0; $cursor < $count; $cursor++) {
                $depth += $tokens[$cursor]->is(['(', '[', '{']) ? 1 : ($tokens[$cursor]->is([')', ']', '}']) ? -1 : 0);

                if ($depth < 0 || ($depth === 0 && $tokens[$cursor]->is(';'))) {
                    break;
                }

                $statement .= $tokens[$cursor]->text;
            }

            $requests[] = ['line' => $token->line, 'statement' => $statement];
        }

        return $requests;
    }

    /** @param  array<string, string>  $imports  lowercase alias => fully qualified class name */
    private function namesHttpFacade(PhpToken $token, string $namespace, array $imports): bool
    {
        if ($token->is(T_STRING) && $namespace === '' && ! isset($imports[strtolower($token->text)]) && $token->text === 'Http') {
            return true;
        }

        if ($token->is(T_NAME_FULLY_QUALIFIED) && $token->text === '\Http') {
            return true;
        }

        return strtolower($this->resolveClassName($token, $namespace, $imports)) === strtolower(Http::class);
    }

    /**
     * The PHP files under `<packageRoot>/src`, keyed by path relative to `src`, sorted by name.
     *
     * @return array<string, string>
     */
    private function sourceFiles(string $packageRoot): array
    {
        $srcPath = rtrim($packageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'src';

        if (! is_dir($srcPath)) {
            return [];
        }

        $files = [];

        foreach ((new Finder)->files()->in($srcPath)->name('*.php')->sortByName() as $file) {
            $files[str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
        }

        return $files;
    }

    /** @return list<PhpToken> */
    private function significantTokens(string $contents): array
    {
        return array_values(array_filter(
            PhpToken::tokenize($contents),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));
    }

    private function isHttpClientClass(string $name): bool
    {
        $name = strtolower(trim($name, '\\'));

        foreach (self::HTTP_CLASSES as $class) {
            if ($name === strtolower($class)) {
                return true;
            }
        }

        foreach (self::HTTP_NAMESPACE_PREFIXES as $prefix) {
            if (str_starts_with($name . '\\', strtolower($prefix))) {
                return true;
            }
        }

        return str_starts_with($name, 'prism\\') && str_ends_with($name, '\prism');
    }

    private function isHttpFunction(string $name): bool
    {
        return preg_match('/^\\\\?curl_[a-z_]+$/i', $name) === 1;
    }

    /**
     * The names a use statement imports, as [fully qualified name, alias, kind] where kind is class, function or
     * const. A statement may import several names separated by commas, each of which may be a group.
     *
     * @param  list<PhpToken>  $statement  the tokens between `use` and `;`
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function useStatementNames(array $statement): array
    {
        $kind = 'class';

        if (isset($statement[0]) && $statement[0]->is([T_FUNCTION, T_CONST])) {
            $kind = $statement[0]->is(T_FUNCTION) ? 'function' : 'const';
            array_shift($statement);
        }

        $segments = [];
        $current = [];
        $depth = 0;

        foreach ($statement as $token) {
            if ($token->is('{')) {
                $depth++;
            } elseif ($token->is('}')) {
                $depth--;
            }

            if ($depth === 0 && $token->is(',')) {
                $segments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        $segments[] = $current;
        $names = [];

        foreach ($segments as $segment) {
            $groupStart = null;
            $prefix = '';

            foreach ($segment as $position => $token) {
                if ($token->is('{')) {
                    $groupStart = $position;

                    break;
                }

                $prefix .= $token->text;
            }

            $entries = [];

            if ($groupStart === null) {
                $entries[] = [$segment, ''];
            } else {
                $entry = [];

                foreach (array_slice($segment, $groupStart + 1) as $token) {
                    if ($token->is([',', '}'])) {
                        if ($entry !== []) {
                            $entries[] = [$entry, $prefix];
                        }

                        $entry = [];

                        continue;
                    }

                    $entry[] = $token;
                }
            }

            foreach ($entries as [$tokens, $entryPrefix]) {
                $name = '';
                $alias = null;
                $entryKind = $kind;

                foreach ($tokens as $position => $token) {
                    if ($token->is(T_AS)) {
                        $alias = $tokens[$position + 1]->text ?? null;

                        break;
                    }

                    if ($position === 0 && $token->is([T_FUNCTION, T_CONST])) {
                        $entryKind = $token->is(T_FUNCTION) ? 'function' : 'const';

                        continue;
                    }

                    $name .= $token->text;
                }

                $name = ltrim($entryPrefix . $name, '\\');

                if ($name === '') {
                    continue;
                }

                $names[] = [$name, $alias ?? (str_contains($name, '\\') ? substr($name, (int) strrpos($name, '\\') + 1) : $name), $entryKind];
            }
        }

        return $names;
    }

    /** @param  array<string, string>  $imports  lowercase alias => fully qualified class name */
    private function resolveClassName(PhpToken $token, string $namespace, array $imports): string
    {
        $name = $token->text;

        if ($token->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($name, '\\');
        }

        if ($token->is(T_NAME_RELATIVE)) {
            return ltrim($namespace . '\\' . substr($name, strlen('namespace\\')), '\\');
        }

        $first = strstr($name, '\\', true);
        $first = $first === false ? $name : $first;

        $import = $imports[strtolower($first)] ?? null;

        if ($import !== null) {
            return $import . substr($name, strlen($first));
        }

        return ltrim($namespace . '\\' . $name, '\\');
    }

    /**
     * Whether a stream call whose argument is a single variable sits in the same statement as an is_file() or
     * file_exists() check of that variable: neither accepts an http URL, so the read only happens for a local file.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function streamGuardedAsFile(array $tokens, int $call): bool
    {
        $argument = $tokens[$call + 2] ?? null;

        if (! $argument instanceof PhpToken || ! $argument->is(T_VARIABLE) || ! ($tokens[$call + 3] ?? null)?->is([')', ','])) {
            return false;
        }

        for ($cursor = $call - 1; $cursor >= 3; $cursor--) {
            if ($tokens[$cursor]->is([';', '{', '}'])) {
                return false;
            }

            if ($tokens[$cursor]->is(T_VARIABLE) && $tokens[$cursor]->text === $argument->text && $tokens[$cursor - 1]->is('(') && $tokens[$cursor + 1]->is(')')
                && in_array(strtolower(ltrim($tokens[$cursor - 2]->text, '\\')), ['is_file', 'file_exists'], true)
                && ! $tokens[$cursor - 3]->is('!')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The reviewed stream declaration on the line before a call: local, remote, or null when there is none.
     *
     * @param  list<string>  $lines
     */
    private function streamDeclaration(array $lines, int $line): ?string
    {
        if (preg_match('/\/\/\s*capell-security-scan:\s*(local|remote)-stream\s+\S/', $lines[$line - 2] ?? '', $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Classifies a stream function's first argument: remote (an http(s) or ftp URL, or a string that starts with
     * one), local (a literal path, a php:// stream, __DIR__, __FILE__ or a path helper) or dynamic.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function streamTarget(array $tokens, int $start): string
    {
        $first = $tokens[$start] ?? null;

        if (! $first instanceof PhpToken) {
            return 'dynamic';
        }

        if ($first->is(T_CONSTANT_ENCAPSED_STRING) || $first->is('"')) {
            $template = $first->is('"');
            $leading = $template ? ($tokens[$start + 1] ?? null) : $first;
            $text = $leading instanceof PhpToken && $leading->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) ? trim($leading->text, '\'"') : '';

            if (preg_match('/^(?:https?|ftps?):\/\//i', $text) === 1) {
                return 'remote';
            }

            // A complete literal names a fixed path. A string built from variables, by interpolation or
            // concatenation, is local only when its fixed start is evidently a local path.
            $built = $template || ($tokens[$start + 1] ?? null)?->is('.') === true;

            if (! $built) {
                return 'local';
            }

            return preg_match('#^(?:/|\./|\.\./|php://)#i', $text) === 1 ? 'local' : 'dynamic';
        }

        if ($first->is([T_DIR, T_FILE])) {
            return 'local';
        }

        // An uploaded or iterated file's own path: SplFileInfo::getPathname() and getRealPath() name a local file.
        if ($first->is(T_VARIABLE) && ($tokens[$start + 1] ?? null)?->is(T_OBJECT_OPERATOR) === true
            && in_array($tokens[$start + 2]->text ?? '', ['getPathname', 'getRealPath'], true)
            && ($tokens[$start + 3] ?? null)?->is('(') === true && ($tokens[$start + 4] ?? null)?->is(')') === true
            && ($tokens[$start + 5] ?? null)?->is([',', ')']) === true) {
            return 'local';
        }

        if ($first->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && ($tokens[$start + 1] ?? null)?->is('(') === true
            && in_array(strtolower(ltrim($first->text, '\\')), self::LOCAL_PATH_CALLS, true)) {
            return 'local';
        }

        return 'dynamic';
    }

    private function className(string $contents): ?string
    {
        if (preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $contents, $namespace) !== 1) {
            return null;
        }

        if (preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|trait|interface|enum)\s+(\w+)/m', $contents, $class) !== 1) {
            return null;
        }

        return trim($namespace[1]) . '\\' . $class[1];
    }

    private function lineAt(string $contents, int $offset): int
    {
        return substr_count(substr($contents, 0, $offset), "\n") + 1;
    }
}
