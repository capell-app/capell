<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Permissions;

use Illuminate\Support\Facades\File;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Lists the abilities a package checks without declaring them.
 *
 * Only checks whose arguments are ability strings name a permission; a check
 * with a model or class argument calls a policy method and is left to the
 * policy, and so is a bare method name such as "update" (Filament's
 * ->authorize('update') checks the record's policy). Permission keys always
 * carry a separator (":", "." or "-"). Shield keys ("Verb:Subject") count as
 * declared when the subject is one Shield generates for the package: a
 * resource's model, or a page or widget class.
 *
 * A check that is not a role permission, such as a Sanctum token ability, is
 * declared with `// capell-permission-scan: token-ability <reason>` on the line
 * before it.
 *
 * @method static list<string> run(string $packagePath, list<string> $declaredKeys)
 */
final class FindUndeclaredPermissionsAction
{
    use AsFake;
    use AsObject;

    /** One quoted ability, optionally followed by a trailing comma, then ")". */
    private const string ABILITY = '\(\s*([\'"])([^\'"\s]+)\1\s*,?\s*\)';

    /** A list of quoted abilities as the only argument. */
    private const string ABILITY_LIST = '\(\s*\[([^\]]*)\]\s*,?\s*\)';

    /** @var list<string> */
    private const array SINGLE_PATTERNS = [
        '/->(?:can|cannot|authorize|allows|denies|inspect)' . self::ABILITY . '/',
        '/Gate::(?:allows|denies|check|authorize|inspect)' . self::ABILITY . '/',
        '/@(?:can|cannot|elsecan)' . self::ABILITY . '/',
    ];

    /** @var list<string> */
    private const array LIST_PATTERNS = [
        '/Gate::(?:any|none|check)' . self::ABILITY_LIST . '/',
        '/->(?:canAny|any|none)' . self::ABILITY_LIST . '/',
        '/@canany' . self::ABILITY_LIST . '/',
    ];

    private const string MIDDLEWARE = '/[\'"]can:([^,\'"\s]+)[\'"]/';

    /** A policy method name: an identifier with no permission-key separator. */
    private const string POLICY_METHOD = '/^[a-z][A-Za-z0-9]*$/';

    private const string OPT_OUT = '/\/\/\s*capell-permission-scan:\s*token-ability\s+\S/';

    /**
     * @param  list<string>  $declaredKeys
     * @return list<string> undeclared abilities, sorted
     */
    public function handle(string $packagePath, array $declaredKeys): array
    {
        $files = $this->files($packagePath);
        $subjects = $this->shieldSubjects($files);
        $undeclared = [];

        foreach ($files as $file) {
            foreach ($this->abilities($this->source($file)) as $ability) {
                if (preg_match(self::POLICY_METHOD, $ability) !== 1
                    && ! in_array($ability, $declaredKeys, true)
                    && ! $this->isGeneratedShieldKey($ability, $subjects)) {
                    $undeclared[$ability] = true;
                }
            }
        }

        $keys = array_keys($undeclared);
        sort($keys);

        return $keys;
    }

    /** @return list<string> */
    public function abilities(string $source): array
    {
        $found = [];

        foreach (self::SINGLE_PATTERNS as $pattern) {
            preg_match_all($pattern, $source, $matches);
            array_push($found, ...$matches[2]);
        }

        foreach (self::LIST_PATTERNS as $pattern) {
            preg_match_all($pattern, $source, $matches);

            foreach ($matches[1] as $list) {
                preg_match_all('/([\'"])([^\'"\s]+)\1/', $list, $items);
                array_push($found, ...$items[2]);
            }
        }

        preg_match_all(self::MIDDLEWARE, $source, $matches);
        array_push($found, ...$matches[1]);

        return array_values(array_unique($found));
    }

    /** PHP comments are dropped so commented-out or documented checks do not count. */
    private function source(SplFileInfo $file): string
    {
        $contents = $this->withoutOptedOutLines($file->getContents());

        if (str_ends_with($file->getFilename(), '.blade.php')) {
            return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $contents);
        }

        $source = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $source .= is_array($token) ? $token[1] : $token;
        }

        return $source;
    }

    /** Blanks each line declared not to be a role permission check by the marker on the line before. */
    private function withoutOptedOutLines(string $contents): string
    {
        $lines = explode("\n", $contents);

        foreach (array_keys($lines) as $index) {
            if ($index > 0 && preg_match(self::OPT_OUT, $lines[$index - 1]) === 1) {
                $lines[$index] = '';
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<SplFileInfo> */
    private function files(string $packagePath): array
    {
        $files = [];

        foreach (['src', 'resources/views', 'routes'] as $directory) {
            $path = rtrim($packagePath, '/') . '/' . $directory;

            if (File::isDirectory($path)) {
                array_push($files, ...File::allFiles($path));
            }
        }

        return array_values(array_filter(
            $files,
            static fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.php'),
        ));
    }

    /**
     * Shield's subjects: a resource's model basename, and page or widget class basenames.
     *
     * @param  list<SplFileInfo>  $files
     * @return list<string>
     */
    private function shieldSubjects(array $files): array
    {
        $subjects = [];

        foreach ($files as $file) {
            $path = $file->getPathname();

            if (! str_contains($path, '/Filament/')) {
                continue;
            }

            if (str_ends_with($file->getFilename(), 'Resource.php')
                && preg_match('/\$model\s*=\s*\\\\?([\w\\\\]+)::class/', $file->getContents(), $model) === 1) {
                $subjects[] = class_basename($model[1]);

                continue;
            }

            if (str_contains($path, '/Pages/') || str_contains($path, '/Widgets/')) {
                $subjects[] = $file->getBasename('.php');
            }
        }

        return array_values(array_unique($subjects));
    }

    /** @param list<string> $subjects */
    private function isGeneratedShieldKey(string $ability, array $subjects): bool
    {
        $parts = explode(':', $ability, 2);

        return count($parts) === 2 && $parts[0] !== '' && in_array($parts[1], $subjects, true);
    }
}
