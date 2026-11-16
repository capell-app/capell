<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Webhooks;

/**
 * Marks an InboundWebhookProvider whose sender posts a JSON body labelled
 * `text/plain`, such as Amazon SNS notifications and subscription confirmations.
 *
 * The receiver then accepts `text/plain` as well as JSON. Nothing else changes:
 * the size limit still applies, and verify() still receives the request with its
 * raw body untouched, so it checks the signature over exactly what was sent and
 * decodes the body itself. Every other content type is still refused with 415.
 */
interface AcceptsTextPlainJsonWebhooks extends InboundWebhookProvider {}
