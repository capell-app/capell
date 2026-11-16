<?php

declare(strict_types=1);

namespace Capell\Core\Http\Controllers\Webhooks;

use Illuminate\Http\Response;

/**
 * Answers a sender's GET check of a webhook URL with an empty 200. It reads
 * nothing from the request and stores nothing.
 */
final class AnswerWebhookValidationController
{
    public function __invoke(): Response
    {
        return new Response('', Response::HTTP_OK);
    }
}
