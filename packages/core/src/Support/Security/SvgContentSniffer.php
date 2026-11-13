<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

/**
 * Decides whether an upload must go through the SvgUpload profile. Any signal
 * counts: a declared SVG type or extension, a sniffed SVG type, or an "<svg"
 * element anywhere in the content. Reading only a leading window fails open,
 * because a long comment, doctype or whitespace prologue pushes the element past
 * it, so streams are read to the end in overlapping chunks.
 */
final class SvgContentSniffer
{
    private const int CHUNK_BYTES = 65_536;

    /**
     * "<svg" in UTF-8, UTF-16LE and UTF-16BE: a UTF-16 upload interleaves NUL bytes
     * and would never match the single-byte form.
     *
     * @var list<string>
     */
    private const array SVG_TAGS = ['<svg', "<\0s\0v\0g\0", "\0<\0s\0v\0g"];

    /** Bytes kept between chunks so a tag split across two reads is still found. */
    private const int CARRY_BYTES = 8;

    public static function declaresSvg(?string $declaredMimeType, ?string $declaredExtension): bool
    {
        $extension = strtolower(ltrim(trim((string) $declaredExtension), '.'));

        return in_array($extension, ['svg', 'svgz'], true)
            || str_contains(strtolower((string) $declaredMimeType), 'svg');
    }

    public static function isSvg(string $contents, ?string $declaredMimeType = null, ?string $declaredExtension = null): bool
    {
        if (self::declaresSvg($declaredMimeType, $declaredExtension)) {
            return true;
        }

        $sniffed = self::sniffMimeType(substr($contents, 0, self::CHUNK_BYTES));

        return ($sniffed !== null && str_contains($sniffed, 'svg')) || self::containsSvgTag($contents);
    }

    /**
     * Reads the stream from its current position to the end and restores the
     * position when the stream is seekable.
     *
     * @param  resource  $stream
     */
    public static function streamIsSvg($stream, ?string $declaredMimeType = null, ?string $declaredExtension = null): bool
    {
        if (self::declaresSvg($declaredMimeType, $declaredExtension)) {
            return true;
        }

        $position = ftell($stream);
        $carry = '';
        $first = true;
        $found = false;

        while (! feof($stream)) {
            $chunk = fread($stream, self::CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            if ($first) {
                $sniffed = self::sniffMimeType($chunk);
                $first = false;

                if ($sniffed !== null && str_contains($sniffed, 'svg')) {
                    $found = true;

                    break;
                }
            }

            // Keep the tail so a tag split across two chunks is still seen.
            if (self::containsSvgTag($carry . $chunk)) {
                $found = true;

                break;
            }

            $carry = substr($carry . $chunk, -self::CARRY_BYTES);
        }

        if (is_int($position) && stream_get_meta_data($stream)['seekable']) {
            fseek($stream, $position);
        }

        return $found;
    }

    private static function containsSvgTag(string $contents): bool
    {
        return array_any(self::SVG_TAGS, static fn (string $tag): bool => stripos($contents, $tag) !== false);
    }

    private static function sniffMimeType(string $contents): ?string
    {
        if ($contents === '' || ! function_exists('finfo_open')) {
            return null;
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($fileInfo === false) {
            return null;
        }

        $mimeType = finfo_buffer($fileInfo, $contents);

        return is_string($mimeType) && $mimeType !== '' ? strtolower($mimeType) : null;
    }
}
