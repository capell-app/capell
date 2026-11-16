<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Webhooks;

/**
 * Marks an InboundWebhookProvider whose sender checks the webhook URL with a GET
 * before it starts posting events, such as Mailchimp when a list webhook is
 * created.
 *
 * `Route::capellWebhook()` then also answers a GET on the same URI with an empty
 * 200, under the same throttle. The GET reads nothing, stores nothing and runs no
 * handler; it only tells the sender the URL exists. Events still arrive only by
 * POST, and are verified there.
 */
interface AnswersWebhookValidationRequests extends InboundWebhookProvider {}
