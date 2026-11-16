<?php

declare(strict_types=1);

namespace Capell\Admin\Policies;

/** Viewing stored inbound webhook events needs `view`; replaying one needs `update`. */
final class InboundWebhookEventPolicy extends GlobalResourcePolicy
{
    protected const string SUBJECT = 'InboundWebhookEvent';
}
