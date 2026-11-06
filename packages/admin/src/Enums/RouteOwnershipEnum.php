<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

/** Who serves a route, decided once as it is registered and recorded on it. */
enum RouteOwnershipEnum: string
{
    /** Registered by a panel's route group: it runs that panel's whole stack on the panel's domains. */
    case Panel = 'panel';

    /** Declared public: something other than a session is its credential, such as a URL signature or token. */
    case Public = 'public';

    /** Explicitly declared host route, which runs only its own middleware. */
    case Host = 'host';

    /** No positive host, panel or public declaration; check against panels as they appear. */
    case Unclassified = 'unclassified';

    /** Admin code that no panel registered, or registered for more than one panel: it is never served. */
    case Refused = 'refused';
}
