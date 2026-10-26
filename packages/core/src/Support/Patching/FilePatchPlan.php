<?php

declare(strict_types=1);

namespace Capell\Core\Support\Patching;

use Capell\Core\Data\Patching\PatchFilePreviewData;
use Capell\Core\Data\Patching\PatchPreviewData;

/** Raw file contents must never be serialised into a client review. */
final readonly class FilePatchPlan
{
    /**
     * @param  array<string, list<string>>  $visibleEnvironmentValues
     * @param  list<string>  $visiblePhpArrayKeys  Patch-owned structural keys only.
     */
    public function __construct(
        public string $path,
        public string $relativePath,
        public ?string $before,
        public string $after,
        public bool $environment = false,
        public bool $panelProvider = false,
        public bool $text = false,
        public ?string $safeAfter = null,
        public array $visibleEnvironmentValues = [],
        public array $visiblePhpArrayKeys = [],
    ) {}

    public function preview(): PatchPreviewData
    {
        clearstatcache(true, $this->path);
        $original = explode("\n", $this->before ?? '');
        $updated = explode("\n", $this->after);
        $start = 0;
        while (isset($original[$start], $updated[$start]) && $original[$start] === $updated[$start]) {
            $start++;
        }

        $oldEnd = count($original);
        $newEnd = count($updated);
        while ($oldEnd > $start && $newEnd > $start && $original[$oldEnd - 1] === $updated[$newEnd - 1]) {
            $oldEnd--;
            $newEnd--;
        }

        // Tokenise whole files so multiline literals retain their lexical context.
        $before = implode("\n", array_slice(explode("\n", $this->redact($this->before ?? '')), $start, $oldEnd - $start));
        $after = $this->safeAfter ?? implode("\n", array_slice(explode("\n", $this->redact($this->after)), $start, $newEnd - $start));

        return new PatchPreviewData(
            files: [new PatchFilePreviewData(
                path: $this->relativePath,
                before: mb_substr($before, 0, 4000),
                after: mb_substr($after, 0, 4000),
                truncated: mb_strlen($before) > 4000 || mb_strlen($after) > 4000,
                sourceFingerprint: $this->sourceFingerprint($this->before),
            )],
            fingerprint: hash('sha256', serialize([$this->path, realpath($this->path), $this->before, $this->after, file_exists($this->path) ? fileperms($this->path) : null])),
            backsUp: $this->before !== null,
        );
    }

    public function sourceFingerprint(?string $content): string
    {
        clearstatcache(true, $this->path);

        return hash('sha256', serialize([$this->path, realpath($this->path), $content, file_exists($this->path) ? fileperms($this->path) : null]));
    }

    private function redact(string $content): string
    {
        if ($this->environment) {
            // Only these non-secret enum/boolean values are suitable for display.
            return implode("\n", array_map(function (string $line): string {
                if (! preg_match('/^([A-Z_][A-Z0-9_]*)=(.*)$/', $line, $match)) {
                    return $line === '' ? '' : '[redacted]';
                }

                $safe = in_array($match[2], $this->visibleEnvironmentValues[$match[1]] ?? [], true);

                return $match[1] . '=' . ($safe ? $match[2] : '[redacted]');
            }, explode("\n", $content)));
        }

        if ($this->text) {
            // Text patches provide only their authored additions; existing host text
            // is never exposed by this fallback projection.
            return implode("\n", array_map(static fn (string $line): string => trim($line) === '' ? '' : '[redacted]', explode("\n", $content)));
        }

        // PHP previews expose only explicitly safe structural keys, never arbitrary host keys or values,
        // interpolated strings, comments or inline HTML. Redact before bounding.
        $tokens = token_get_all($content);
        $safe = '';
        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                $safe .= $token;

                continue;
            }

            [$type, $text] = $token;
            if ($type === T_OPEN_TAG) {
                $safe .= $text;

                continue;
            }

            if (in_array($type, [T_COMMENT, T_DOC_COMMENT, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML, T_LNUMBER, T_DNUMBER], true)) {
                $safe .= '[redacted]' . str_repeat("\n", substr_count($text, "\n"));

                continue;
            }

            if ($type === T_CONSTANT_ENCAPSED_STRING) {
                $next = $index + 1;
                while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                    $next++;
                }

                // Only patch-owned schema names communicate scope; host keys may be secrets.
                $safe .= isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_DOUBLE_ARROW
                    && in_array(substr($text, 1, -1), $this->visiblePhpArrayKeys, true)
                    ? $text : "'[redacted]'" . str_repeat("\n", substr_count($text, "\n"));

                continue;
            }

            $safe .= $text;
        }

        return $safe;
    }
}
