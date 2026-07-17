<?php

declare(strict_types=1);

namespace Capell\Core\Actions\Content;

use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

class ExtractTextContentAction
{
    use AsFake;
    use AsObject;

    /**
     * Extracts plain text from a string, array, or JSON-encoded content.
     * Optionally applies a length limit to the extracted text.
     *
     * @param  string|array<mixed>|null  $content
     */
    public function handle(string|array|null $content, ?int $limit = null): string
    {
        $text = $this->extract($content);

        if ($limit !== null && $limit > 0) {
            return $this->limitWithoutSplittingWords($text, $limit);
        }

        return $text;
    }

    /**
     * @param  string|array<mixed>|null  $content
     */
    private function extract(string|array|null $content): string
    {
        if ($content === null) {
            return '';
        }

        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['content'])) {
                return $this->extract($decoded['content']);
            }

            return trim(strip_tags($content));
        }

        $texts = [];
        $iterator = function (mixed $value) use (&$texts, &$iterator): void {
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    $iterator($nestedValue);
                }

                return;
            }

            if (is_string($value)) {
                $texts[] = trim(strip_tags($value));
            }
        };
        $iterator($content);

        return trim(implode(' ', $texts));
    }

    private function limitWithoutSplittingWords(string $text, int $limit): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $slice = mb_substr($text, 0, $limit);

        // Find the last whitespace position within the slice
        $lastWhitespacePos = $this->mbLastWhitespacePos($slice);

        if ($lastWhitespacePos !== null) {
            return rtrim(mb_substr($slice, 0, $lastWhitespacePos));
        }

        // No whitespace found; return the hard slice (a single long word)
        return rtrim($slice);
    }

    private function mbLastWhitespacePos(string $text): ?int
    {
        // Normalize whitespace to spaces for consistent detection
        $normalized = preg_replace('/\s+/u', ' ', $text);
        if ($normalized === null) {
            $normalized = $text;
        }

        // If normalized differs in length, we still search in original using regex
        $matches = [];
        if (preg_match_all('/\s/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            $last = array_pop($matches[0]);

            return is_array($last) ? $last[1] : null;
        }

        return null;
    }
}
