<?php

declare(strict_types=1);

namespace Capell\Core\Support\Security;

use Capell\Core\Enums\HtmlSanitizerProfile;
use InvalidArgumentException;

/**
 * The resolved allowlist for one HTML sanitiser profile. Built from Core's
 * definition and any registered HtmlSanitizerProfileExtension, never from
 * caller input.
 */
final readonly class HtmlSanitizerPolicy
{
    /**
     * Elements removed with everything inside them in every HTML profile.
     *
     * @var array<string, true>
     */
    private const array DISCARD_WITH_CONTENT = [
        'applet' => true,
        'audio' => true,
        'canvas' => true,
        'embed' => true,
        'form' => true,
        'iframe' => true,
        'listing' => true,
        'math' => true,
        'noembed' => true,
        'noframes' => true,
        'noscript' => true,
        'object' => true,
        'plaintext' => true,
        'script' => true,
        'style' => true,
        'svg' => true,
        'template' => true,
        'video' => true,
        'xmp' => true,
    ];

    /** @var array<string, list<string>> */
    private const array STRICT_ELEMENTS = [
        'a' => ['href', 'title'],
        'blockquote' => [],
        'br' => [],
        'code' => [],
        'dd' => [],
        'div' => [],
        'dl' => [],
        'dt' => [],
        'em' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'h5' => [],
        'h6' => [],
        'hr' => [],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'li' => [],
        'ol' => [],
        'p' => [],
        'pre' => [],
        'section' => ['id', 'data-top-extensions-showcase'],
        'span' => [],
        'strong' => [],
        'table' => [],
        'tbody' => [],
        'td' => [],
        'th' => [],
        'thead' => [],
        'tr' => [],
        'ul' => [],
    ];

    /** @var array<string, list<string>> */
    private const array RICH_TEXT_ADDITIONS = [
        'abbr' => ['title'],
        'b' => [],
        'caption' => [],
        'del' => [],
        'figcaption' => [],
        'figure' => [],
        'i' => [],
        'ins' => [],
        'kbd' => [],
        'mark' => [],
        'ol' => ['start'],
        'q' => [],
        's' => [],
        'small' => [],
        'sub' => [],
        'sup' => [],
        'td' => ['colspan', 'rowspan'],
        'tfoot' => [],
        'th' => ['colspan', 'rowspan', 'scope'],
        'u' => [],
    ];

    /** @var array<string, list<string>> */
    private const array COMMENT_ELEMENTS = [
        'a' => ['href'],
        'b' => [],
        'blockquote' => [],
        'br' => [],
        'code' => [],
        'em' => [],
        'i' => [],
        'li' => [],
        'ol' => [],
        'p' => [],
        'pre' => [],
        'strong' => [],
        'ul' => [],
    ];

    /** @var array<string, true> */
    private const array RICH_TEXT_IDENTIFIER_ELEMENTS = [
        'h2' => true,
        'h3' => true,
        'h4' => true,
        'h5' => true,
        'h6' => true,
        'section' => true,
    ];

    /**
     * Exact host => the path prefixes that serve a player. Paths outside them
     * (for example a redirect endpoint) are refused.
     *
     * @var array<string, list<string>>
     */
    private const array DEFAULT_EMBED_HOSTS = [
        'player.vimeo.com' => ['/video/'],
        'www.youtube-nocookie.com' => ['/embed/'],
    ];

    /**
     * @param  array<string, list<string>>  $elements  element name => allowed attribute names
     * @param  array<string, true>  $identifierElements  elements whose validated id survives
     * @param  array<string, list<string>>  $embedHosts  exact HTTPS host => player path prefixes an iframe may load
     */
    private function __construct(
        public HtmlSanitizerProfile $profile,
        public array $elements,
        public bool $allowsClasses,
        public array $identifierElements,
        public string $externalLinkRel,
        public array $embedHosts,
    ) {}

    public static function for(HtmlSanitizerProfile $profile): self
    {
        $richText = self::merge(self::STRICT_ELEMENTS, self::RICH_TEXT_ADDITIONS);

        return match ($profile) {
            HtmlSanitizerProfile::Strict => new self($profile, self::STRICT_ELEMENTS, false, [], 'nofollow noopener noreferrer', []),
            HtmlSanitizerProfile::RichText => new self($profile, $richText, true, self::RICH_TEXT_IDENTIFIER_ELEMENTS, 'nofollow noopener noreferrer', []),
            HtmlSanitizerProfile::Comment => new self($profile, self::COMMENT_ELEMENTS, false, [], 'nofollow ugc noopener noreferrer', []),
            HtmlSanitizerProfile::Embed => new self(
                $profile,
                self::merge($richText, ['iframe' => ['src', 'title', 'width', 'height', 'allowfullscreen']]),
                true,
                self::RICH_TEXT_IDENTIFIER_ELEMENTS,
                'nofollow noopener noreferrer',
                self::DEFAULT_EMBED_HOSTS,
            ),
            HtmlSanitizerProfile::SvgUpload => throw new InvalidArgumentException('SVG uploads are sanitised as XML by SvgUploadSanitizer, not by an HTML policy.'),
        };
    }

    public function extendedWith(HtmlSanitizerProfileExtension $extension): self
    {
        throw_if(
            $extension->embedHosts !== [] && $this->profile !== HtmlSanitizerProfile::Embed,
            InvalidArgumentException::class,
            'Only the embed profile accepts embed hosts.',
        );

        return new self(
            $this->profile,
            self::merge($this->elements, $extension->elements),
            $this->allowsClasses,
            $this->identifierElements,
            $this->externalLinkRel,
            self::merge($this->embedHosts, $extension->embedHosts),
        );
    }

    public function allowsElement(string $element): bool
    {
        return isset($this->elements[$element]);
    }

    public function discardsWithContent(string $element): bool
    {
        return isset(self::DISCARD_WITH_CONTENT[$element]) && ! $this->allowsElement($element);
    }

    /**
     * @return list<string>
     */
    public function attributesFor(string $element): array
    {
        return $this->elements[$element] ?? [];
    }

    public function allowsIdentifierOn(string $element): bool
    {
        return isset($this->identifierElements[$element]) || in_array('id', $this->attributesFor($element), true);
    }

    public function allowsEmbed(string $host, string $path): bool
    {
        // Dot segments and encoded separators would leave the player prefix once the browser normalises the path.
        if (preg_match('#/\.|%2e|%2f|%5c#i', $path) === 1) {
            return false;
        }

        return array_any(
            $this->embedHosts[$host] ?? [],
            static fn (string $prefix): bool => str_starts_with($path, $prefix) && strlen($path) > strlen($prefix),
        );
    }

    /**
     * @param  array<string, list<string>>  $base
     * @param  array<string, list<string>>  $additions
     * @return array<string, list<string>>
     */
    private static function merge(array $base, array $additions): array
    {
        foreach ($additions as $element => $attributes) {
            $base[$element] = array_values(array_unique([...$base[$element] ?? [], ...$attributes]));
        }

        ksort($base);

        return $base;
    }
}
