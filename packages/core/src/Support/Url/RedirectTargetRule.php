<?php

declare(strict_types=1);

namespace Capell\Core\Support\Url;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Override;

/**
 * Laravel and Filament validation for a stored redirect or link destination.
 */
final readonly class RedirectTargetRule implements ValidationRule
{
    public function __construct(
        private RedirectContext $context = new RedirectContext,
    ) {}

    #[Override]
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('capell-core::message.redirect_target_invalid'));

            return;
        }

        $result = resolve(RedirectTargetPolicy::class)->validate($value, $this->context);

        if ($result->allowed()) {
            return;
        }

        $fail(match ($result->rejection) {
            RedirectTargetRejection::Empty => __('capell-core::message.redirect_target_empty'),
            RedirectTargetRejection::ControlCharacter => __('capell-core::message.redirect_target_control_character'),
            RedirectTargetRejection::ProtocolRelative => __('capell-core::message.redirect_target_protocol_relative'),
            RedirectTargetRejection::RelativeNotAllowed => __('capell-core::message.redirect_target_relative_not_allowed'),
            RedirectTargetRejection::Scheme => __('capell-core::message.redirect_target_scheme'),
            RedirectTargetRejection::Credentials => __('capell-core::message.redirect_target_credentials'),
            RedirectTargetRejection::Host => __('capell-core::message.redirect_target_host'),
            RedirectTargetRejection::Malformed, null => __('capell-core::message.redirect_target_malformed'),
        });
    }
}
