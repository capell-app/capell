<?php

declare(strict_types=1);

namespace Capell\Core\Enums\Messaging;

/**
 * What built an outbound message. A raw `Mail::raw()` or `Mail::send()` call has no class to name,
 * so it is unattributed: that is also what injected code would use, so it is kept as its own source.
 */
enum OutboundMessageKind: string
{
    case Notification = 'notification';
    case Mailable = 'mailable';
    case Unattributed = 'unattributed';
}
