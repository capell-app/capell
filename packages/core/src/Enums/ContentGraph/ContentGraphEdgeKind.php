<?php

declare(strict_types=1);

namespace Capell\Core\Enums\ContentGraph;

enum ContentGraphEdgeKind: string
{
    case BelongsToSite = 'belongs_to_site';
    case BelongsToLanguage = 'belongs_to_language';
    case UsesLayout = 'uses_layout';
    case UsesTheme = 'uses_theme';
    case UsesMedia = 'uses_media';
    case UsesSettings = 'uses_settings';
    case CanonicalizesTo = 'canonicalizes_to';
    case RelatesToPage = 'relates_to_page';
    case LinksToPage = 'links_to_page';
    case ResolvesToPage = 'resolves_to_page';
    case RedirectsToUrl = 'redirects_to_url';
    case DescribesPage = 'describes_page';
    case FoundOnPage = 'found_on_page';
}
