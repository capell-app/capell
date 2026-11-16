<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Webhooks;

/**
 * Marks an InboundWebhookProvider whose sender posts
 * `application/x-www-form-urlencoded` bodies, such as Mailchimp list webhooks.
 *
 * The receiver then accepts a form body as well as JSON. Nothing else changes:
 * the size limit still applies, and verify() still receives the request with its
 * raw body untouched, so it checks the signature over exactly what was sent and
 * reads the fields itself. Every other content type is still refused with 415.
 */
interface AcceptsFormEncodedWebhooks extends InboundWebhookProvider {}
