<?php

declare(strict_types=1);

namespace Capell\Core\Contracts\Privacy;

use Capell\Core\Data\Privacy\OpenPrivacyReviewData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Illuminate\Database\Eloquent\Model;

/**
 * The review holds a privacy package keeps, for an eraser that has to read them across subjects or settle one itself:
 * pruning that must skip rows a reviewer retained, an account link that settles the review its email match opened, or
 * a provider confirming it erased its copy. The package that runs privacy requests binds it; without one there are no
 * holds, nothing is retained and nothing can be resolved. The read methods return nothing while the ledger's storage
 * is not available, such as before its migrations have run.
 */
interface PrivacyReviewLedger
{
    public const string RESOLUTION_LINKED = 'linked';

    public const string RESOLUTION_UNRELATED = 'unrelated';

    public const string RESOLUTION_ERASED = 'erased';

    public const string RESOLUTION_RETAIN = 'retain';

    public const array RESOLUTIONS = [
        self::RESOLUTION_LINKED,
        self::RESOLUTION_UNRELATED,
        self::RESOLUTION_ERASED,
        self::RESOLUTION_RETAIN,
    ];

    /**
     * Records a reviewer retained for this contributor, whichever person's request held them.
     *
     * @return array<string, list<int>> candidate ids keyed by candidate type
     */
    public function retained(string $contributor): array;

    /**
     * Holds on these records that nobody has settled yet.
     *
     * @param  list<int>  $candidateIds
     * @param  bool  $includeDelivered  also return holds whose data was delivered to the person but not settled for good
     * @param  bool  $onOpenRequestsOnly  leave out holds whose request was rejected, cancelled or finished, or cannot be
     *                                    found
     * @return list<OpenPrivacyReviewData>
     */
    public function open(string $contributor, string $candidateType, array $candidateIds, bool $includeDelivered = false, bool $onOpenRequestsOnly = false): array;

    /**
     * Settles one hold returned by open(). Returns false only when a reviewer settled it first, which leaves their
     * resolution in place; a caller that needed this resolution must treat false as not done. A resolution the ledger
     * does not accept for this hold, such as linked outside the link channel or retain for a contributor that does not
     * honour retained scopes, throws instead.
     *
     * @param  value-of<self::RESOLUTIONS>  $resolution
     * @param  Model  $resolver  the person or connection that settled it, kept as the resolver of record
     * @param  string  $channel  where it was settled, such as 'console' or 'link'
     */
    public function resolve(OpenPrivacyReviewData $review, string $resolution, Model $resolver, string $note, string $channel): bool;

    /**
     * Records already held for this person's requests, settled or not.
     *
     * @return list<int>
     */
    public function held(PrivacySubject $subject, string $contributor, string $candidateType): array;
}
