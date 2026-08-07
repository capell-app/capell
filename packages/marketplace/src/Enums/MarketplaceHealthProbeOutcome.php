<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

/**
 * What a single post-operation probe actually established.
 *
 * Skipped is a first-class outcome rather than a quiet pass. A host whose
 * APP_URL does not resolve from inside itself is a normal, healthy host — very
 * common behind a load balancer or in a container — so a probe that could not
 * connect has proved nothing either way, and saying so is the only honest
 * report. Folding it into Passed would let a genuinely broken site look
 * verified.
 */
enum MarketplaceHealthProbeOutcome: string
{
    case Passed = 'passed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
