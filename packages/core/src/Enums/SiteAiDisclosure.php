<?php

declare(strict_types=1);

namespace Capell\Core\Enums;

/**
 * Whether a site tells visitors and machines which pages were written with AI.
 * Off by default; Marker adds `<meta name="ai-disclosure">` to those pages.
 */
enum SiteAiDisclosure: string
{
    case Off = 'off';
    case Marker = 'marker';

    public const string META_KEY = 'ai_disclosure';

    /** Site setting that shows visitors a short notice on AI-written pages. Independent of the marker. */
    public const string NOTICE_META_KEY = 'ai_notice';
}
