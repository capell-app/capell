<?php

declare(strict_types=1);

namespace Capell\Frontend\Support\Privacy;

use RuntimeException;

/**
 * The default consent runtime's source and the versioned URL pages load it from. The URL
 * names the script's content hash and nothing about the visitor, so a browser or CDN can
 * cache it for as long as the script is unchanged.
 */
final class PrivacyRuntimeScript
{
    private ?string $contents = null;

    public function contents(): string
    {
        if ($this->contents !== null) {
            return $this->contents;
        }

        $script = file_get_contents(dirname(__DIR__, 3) . '/resources/js/privacy-runtime.js');

        throw_if(! is_string($script) || $script === '', RuntimeException::class, 'The Capell consent runtime script is missing from the Frontend package.');

        return $this->contents = $script;
    }

    /** Identifies the script's exact bytes, so a cached copy can only ever be the one its URL names. */
    public function version(): string
    {
        return substr(hash('sha256', $this->contents()), 0, 16);
    }

    /** Relative, so it follows the site's own host; the hash changes the URL when the script does. */
    public function url(): string
    {
        return route('capell-frontend.privacy-runtime', ['v' => $this->version()], absolute: false);
    }
}
