<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/**
 * Named allowlists for HTML that reaches public pages. Strict is the default
 * and the narrowest; every other profile is opted into by the caller.
 */
enum HtmlSanitizerProfile: string
{
    /** Short public copy: inline formatting, lists, tables, safe links and images. No classes. */
    case Strict = 'strict';

    /** Editor-authored articles: Strict plus figures, captions, inline semantics, validated classes and heading ids. */
    case RichText = 'rich_text';

    /** Visitor-authored text: paragraphs, emphasis, quotes, code, lists and user-generated links only. */
    case Comment = 'comment';

    /** Rich text plus sandboxed iframes from an allowlist of HTTPS embed hosts. */
    case Embed = 'embed';

    /** Uploaded SVG documents: an SVG element and attribute allowlist parsed as XML, never as HTML. */
    case SvgUpload = 'svg_upload';

    public function isExtensible(): bool
    {
        return $this !== self::SvgUpload;
    }
}
