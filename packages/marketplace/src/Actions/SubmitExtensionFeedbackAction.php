<?php

declare(strict_types=1);

namespace Capell\Marketplace\Actions;

use Capell\Core\Actions\Marketplace\ResolveExtensionLicenceDecisionAction;
use Capell\Marketplace\Data\ExtensionFeedbackData;
use Capell\Marketplace\Services\MarketplaceClient;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

final class SubmitExtensionFeedbackAction
{
    use AsAction;

    public function __construct(private readonly MarketplaceClient $marketplace) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ExtensionFeedbackData $feedback): array
    {
        $decision = ResolveExtensionLicenceDecisionAction::run(
            slug: $feedback->slug,
            action: $this->licenceDecisionAction($feedback),
            domain: '',
        );

        if (! $this->decisionAllowsFeedback($feedback, $decision->canRate, $decision->canComment)) {
            throw new RuntimeException($decision->reason ?? (string) __('capell-marketplace::marketplace.feedback.blocked'));
        }

        return $this->marketplace->submitExtensionFeedback($feedback);
    }

    private function licenceDecisionAction(ExtensionFeedbackData $feedback): string
    {
        return $this->hasCommentPayload($feedback) ? 'comment' : 'rate';
    }

    private function decisionAllowsFeedback(ExtensionFeedbackData $feedback, bool $canRate, bool $canComment): bool
    {
        if ($this->hasCommentPayload($feedback)) {
            return $canComment;
        }

        return $feedback->rating !== null && $canRate;
    }

    private function hasCommentPayload(ExtensionFeedbackData $feedback): bool
    {
        if ($this->filledString($feedback->comment)) {
            return true;
        }

        return $this->filledString($feedback->tip);
    }

    private function filledString(?string $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
