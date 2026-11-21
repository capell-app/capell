<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Security;

use Capell\Core\Support\Security\CspHashSource;

/**
 * Declares the Content Security Policy hash sources for inline scripts and
 * styles a package writes into public HTML.
 *
 * Full-page HTML is cached and served without PHP, so a per-request nonce would
 * be frozen into the shared file and reused by every visitor. An inline script
 * or style is instead authorised by the hash of its exact text. A CSP hash
 * matches the element's text content only, never its attributes, so a package
 * keeps the hash stable by emitting a static body and carrying every variable
 * value in attributes or a JSON block.
 *
 * Tag implementations with {@see self::TAG}. Return only `'sha256-…'`,
 * `'sha384-…'` or `'sha512-…'` sources, built with
 * {@see CspHashSource::forContent()} from the same
 * bytes the render hook emits. Core drops any other value, so a provider cannot
 * widen a policy with `'unsafe-inline'` or an origin.
 *
 * @experimental
 */
interface ProvidesCspHashSources
{
    public const string TAG = 'capell.security.csp_hash_source_provider';

    /**
     * @return list<string> hash sources for inline `<script>` bodies, for `script-src`
     */
    public function scriptHashSources(): array;

    /**
     * @return list<string> hash sources for inline `<style>` bodies, for `style-src`
     */
    public function styleHashSources(): array;
}
