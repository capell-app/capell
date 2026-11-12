<?php

declare(strict_types=1);

namespace Capell\Core\Support\Url;

/**
 * The one rule set for a destination a person supplies and Capell later stores, links
 * to or redirects to (redirect rules, form success pages, call-to-action links).
 *
 * Browsers strip tab and newline characters anywhere in a URL and treat a backslash
 * like a slash, so `/\evil.example`, "/\t/evil.example" and `https://site\@evil.example`
 * all leave the site even though they look local. The policy refuses those forms instead
 * of rewriting them, and returns an accepted target exactly as given (case, query and
 * fragment preserved). Callers trim input themselves; surrounding whitespace is refused.
 */
final class RedirectTargetPolicy
{
    public function validate(string $target, RedirectContext $context = new RedirectContext): RedirectTargetResult
    {
        if (trim($target) === '') {
            return RedirectTargetResult::reject(RedirectTargetRejection::Empty);
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $target) === 1) {
            return RedirectTargetResult::reject(RedirectTargetRejection::ControlCharacter);
        }

        if ($target[0] === '/' || $target[0] === '\\') {
            return $this->validateRelative($target, $context);
        }

        return $this->validateAbsolute($target, $context);
    }

    private function validateRelative(string $target, RedirectContext $context): RedirectTargetResult
    {
        if (str_starts_with(str_replace('\\', '/', substr($target, 0, 2)), '//') || $target[0] === '\\') {
            return RedirectTargetResult::reject(RedirectTargetRejection::ProtocolRelative);
        }

        if (! $context->allowRelative) {
            return RedirectTargetResult::reject(RedirectTargetRejection::RelativeNotAllowed);
        }

        return RedirectTargetResult::allow($target);
    }

    private function validateAbsolute(string $target, RedirectContext $context): RedirectTargetResult
    {
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $target, $matches) !== 1) {
            return RedirectTargetResult::reject(RedirectTargetRejection::Malformed);
        }

        if (! in_array(strtolower($matches[1]), $context->schemes, true)) {
            return RedirectTargetResult::reject(RedirectTargetRejection::Scheme);
        }

        // The authority is everything after "scheme:" up to the first path, query or
        // fragment delimiter. Browsers end it at a backslash too, which PHP does not.
        $rest = substr($target, strlen($matches[0]));

        if (! str_starts_with($rest, '//')) {
            return RedirectTargetResult::reject(RedirectTargetRejection::Malformed);
        }

        $authority = substr($rest, 2);
        $authorityEnd = strcspn($authority, '/?#');
        $authority = substr($authority, 0, $authorityEnd);

        if ($authority === '' || preg_match('/[\s\\\\]/', $authority) === 1) {
            return RedirectTargetResult::reject(RedirectTargetRejection::Malformed);
        }

        if (str_contains($authority, '@')) {
            return RedirectTargetResult::reject(RedirectTargetRejection::Credentials);
        }

        $host = parse_url($target, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return RedirectTargetResult::reject(RedirectTargetRejection::Malformed);
        }

        if ($context->allowedHosts !== null && ! in_array(strtolower(rtrim($host, '.')), $context->allowedHosts, true)) {
            return RedirectTargetResult::reject(RedirectTargetRejection::Host);
        }

        return RedirectTargetResult::allow($target);
    }
}
