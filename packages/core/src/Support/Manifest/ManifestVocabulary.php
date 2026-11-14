<?php

declare(strict_types=1);

namespace Capell\Core\Support\Manifest;

/**
 * The closed value lists a manifest may use. The shipped JSON schema repeats these as `enum`
 * values and a test keeps the two in step.
 *
 * @internal
 */
final class ManifestVocabulary
{
    public const string SCHEMA_KEY = '$schema';

    /** @var list<string> */
    public const array PRODUCT_TIERS = ['free', 'premium', 'core'];

    /** @var list<string> */
    public const array PROPOSED_LICENSES = ['free', 'paid', 'included', 'private'];

    /** @var list<string> */
    public const array REQUESTED_CERTIFICATIONS = ['uncertified', 'community', 'partner', 'first-party'];

    /** @var list<string> */
    public const array SUPPORT_POLICIES = ['community', 'standard', 'priority', 'capell-first-party', 'project', 'author'];

    private function __construct() {}
}
