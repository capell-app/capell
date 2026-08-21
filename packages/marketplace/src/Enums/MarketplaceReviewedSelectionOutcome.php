<?php

declare(strict_types=1);

namespace Capell\Marketplace\Enums;

enum MarketplaceReviewedSelectionOutcome: string
{
    case Rejected = 'rejected';
    case LicenceValidationFailed = 'licence_validation_failed';
    case HostedFlowRedirect = 'hosted_flow_redirect';
    case PurchaseFallback = 'purchase_fallback';
    case AccountActionRedirect = 'account_action_redirect';
    case Queued = 'queued';
    case PresentableFailure = 'presentable_failure';
}
