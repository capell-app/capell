<?php

declare(strict_types=1);

namespace Capell\Core\Support\SiteSpec;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Decides whether a stored media item is the image a SiteSpec download would
 * attach. The download's source URL hash matches an item an earlier import
 * attached from that URL; the file's content hash matches the same image however it
 * arrived (uploaded in the admin, or exported from this site and imported back), so
 * applying a spec never replaces an image with an identical copy.
 */
final class SiteSpecAttachedMedia
{
    public static function matches(Media $media, SiteSpecMediaDownload $download): bool
    {
        if ($media->getCustomProperty('capell.site_spec.source_hash') === $download->sourceHash) {
            return true;
        }

        $stored = $media->getCustomProperty('capell.site_spec.content_hash');

        if (is_string($stored)) {
            return $stored === $download->contentHash;
        }

        // An image nobody has hashed yet: a different size cannot match, so only same-sized files are read,
        // and the hash is kept so the file is read at most once.
        if ((int) $media->size !== (int) filesize($download->path)) {
            return false;
        }

        $hash = self::hashOf($media);

        if ($hash !== null) {
            $media->setCustomProperty('capell.site_spec.content_hash', $hash);
            $media->saveQuietly();
        }

        return $hash === $download->contentHash;
    }

    private static function hashOf(Media $media): ?string
    {
        try {
            $stream = $media->stream();
        } catch (Throwable) {
            return null;
        }

        try {
            $context = hash_init('sha256');

            while (! feof($stream)) {
                $chunk = fread($stream, 8192);

                if ($chunk === false) {
                    return null;
                }

                hash_update($context, $chunk);
            }

            return hash_final($context);
        } catch (Throwable) {
            return null;
        } finally {
            fclose($stream);
        }
    }
}
