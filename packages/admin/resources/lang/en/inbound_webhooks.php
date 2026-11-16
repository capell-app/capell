<?php

declare(strict_types=1);

return [
    'list_subheading' => 'Webhook deliveries from payment, email and other providers. Each event is stored once and handled on the queue; replay one after fixing the cause of its failure.',
    'provider' => 'Provider',
    'type' => 'Event type',
    'subject' => 'Subject',
    'status' => 'Status',
    'attempts' => 'Attempts',
    'last_error' => 'Last error',
    'occurred_at' => 'Sent by provider',
    'processed_at' => 'Processed',
    'statuses' => [
        'received' => 'Waiting',
        'processing' => 'Processing',
        'processed' => 'Processed',
        'ignored' => 'Ignored',
        'failed' => 'Failed',
    ],
    'empty_heading' => 'No webhook events yet',
    'empty_description' => 'Events appear here when a connected provider sends a webhook to this site.',
    'replay' => 'Replay',
    'replay_heading' => 'Replay this webhook event?',
    'replay_description' => 'The event is queued for its handler again. Handlers are written to be safe to repeat, but check the cause of any failure first.',
    'replay_force' => 'Apply it again',
    'replay_force_help' => 'This event was already processed. Turn this on only if its handler must apply it a second time.',
    'replay_outcomes' => [
        'queued' => 'The webhook event is queued for its handler again.',
        'ignored' => 'This event was ignored because its provider does not handle its type, so there is nothing to replay.',
        'already-processed' => 'This event was already processed, so it was not queued. Turn on "Apply it again" to replay it.',
        'already-queued' => 'This event is already queued or running, so it was not queued again. Wait for it to finish, then check its status.',
    ],
];
