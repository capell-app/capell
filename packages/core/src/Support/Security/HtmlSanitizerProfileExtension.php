<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use InvalidArgumentException;

/**
 * Markup a package adds to an HTML sanitiser profile. The constructor refuses
 * anything that could execute script, load a resource, restyle the page or
 * clobber page globals: an extension can widen a profile, never bypass it.
 */
final readonly class HtmlSanitizerProfileExtension
{
    /**
     * Elements that execute, load or embed content, change document structure or
     * submit data. Embeds come only from the Embed profile's own iframe rule.
     *
     * @var array<string, true>
     */
    public const array FORBIDDEN_ELEMENTS = [
        'applet' => true, 'audio' => true, 'base' => true, 'body' => true, 'button' => true,
        'canvas' => true, 'dialog' => true, 'embed' => true, 'fieldset' => true, 'form' => true,
        'frame' => true, 'frameset' => true, 'head' => true, 'html' => true, 'iframe' => true,
        'input' => true, 'link' => true, 'listing' => true, 'math' => true, 'meta' => true,
        'noembed' => true, 'noframes' => true, 'noscript' => true, 'object' => true, 'option' => true, 'param' => true, 'picture' => true,
        'plaintext' => true, 'portal' => true, 'script' => true, 'select' => true, 'slot' => true, 'source' => true,
        'style' => true, 'svg' => true, 'template' => true, 'textarea' => true, 'title' => true,
        'track' => true, 'video' => true, 'xmp' => true,
    ];

    /**
     * Attributes that carry URLs the sanitiser does not validate, inline CSS,
     * nested documents, element identity the profile governs itself, or
     * behaviour the browser or page scripts act on.
     *
     * @var array<string, true>
     */
    public const array FORBIDDEN_ATTRIBUTES = [
        'action' => true, 'allow' => true, 'attributionsrc' => true, 'autofocus' => true, 'background' => true, 'cite' => true,
        'class' => true, 'codebase' => true, 'content' => true, 'contenteditable' => true, 'data' => true,
        'dynsrc' => true, 'form' => true, 'formaction' => true, 'href' => true, 'http-equiv' => true,
        'id' => true, 'integrity' => true, 'is' => true, 'longdesc' => true, 'lowsrc' => true,
        'manifest' => true, 'name' => true, 'nonce' => true, 'ping' => true, 'popover' => true,
        'popovertarget' => true, 'popovertargetaction' => true, 'poster' => true, 'rel' => true,
        'sandbox' => true, 'slot' => true, 'src' => true, 'srcdoc' => true, 'srcset' => true,
        'style' => true, 'target' => true, 'usemap' => true, 'xmlns' => true,
    ];

    /**
     * Attribute prefixes that front-end frameworks bind to behaviour.
     *
     * @var list<string>
     */
    private const array FORBIDDEN_ATTRIBUTE_PREFIXES = ['on', 'x-', 'hx-', 'wire', 'v-', 'ng-', 'aria-owns'];

    /**
     * @param  array<string, list<string>>  $elements  element name => extra attribute names
     * @param  array<string, list<string>>  $embedHosts  exact HTTPS host => player path prefixes the Embed profile may frame
     */
    public function __construct(
        public array $elements = [],
        public array $embedHosts = [],
    ) {
        foreach ($elements as $element => $attributes) {
            $this->assertElement($element);

            foreach ($attributes as $attribute) {
                $this->assertAttribute($element, $attribute);
            }
        }

        foreach ($embedHosts as $host => $pathPrefixes) {
            $this->assertEmbedHost($host);
            $this->assertEmbedPaths($host, $pathPrefixes);
        }
    }

    private function assertElement(string $element): void
    {
        // Custom elements (with a hyphen) run whatever behaviour their page script defines.
        if (preg_match('/^[a-z][a-z0-9]{0,15}$/', $element) !== 1 || isset(self::FORBIDDEN_ELEMENTS[$element])) {
            throw new InvalidArgumentException(sprintf('A sanitiser profile cannot allow the [%s] element.', $element));
        }
    }

    private function assertAttribute(string $element, string $attribute): void
    {
        $forbidden = preg_match('/^[a-z][a-z0-9-]{0,40}$/', $attribute) !== 1
            || isset(self::FORBIDDEN_ATTRIBUTES[$attribute])
            || array_any(self::FORBIDDEN_ATTRIBUTE_PREFIXES, static fn (string $prefix): bool => str_starts_with($attribute, $prefix));

        if ($forbidden) {
            throw new InvalidArgumentException(sprintf('A sanitiser profile cannot allow the [%s] attribute on [%s].', $attribute, $element));
        }
    }

    /**
     * @param  list<string>  $pathPrefixes
     */
    private function assertEmbedPaths(string $host, array $pathPrefixes): void
    {
        // A whole host is never framed: a player host also serves redirects and pages.
        $valid = $pathPrefixes !== [] && array_all(
            $pathPrefixes,
            static fn (string $prefix): bool => preg_match('#^/[a-z0-9_-]+(?:/[a-z0-9_-]+)*/$#', $prefix) === 1,
        );

        if (! $valid) {
            throw new InvalidArgumentException(sprintf('Embed host [%s] needs one or more player path prefixes such as "/embed/".', $host));
        }
    }

    private function assertEmbedHost(string $host): void
    {
        $valid = $host === strtolower($host)
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && str_contains($host, '.')
            && filter_var($host, FILTER_VALIDATE_IP) === false;

        if (! $valid) {
            throw new InvalidArgumentException(sprintf('[%s] is not an exact lower-case embed host name.', $host));
        }
    }
}
