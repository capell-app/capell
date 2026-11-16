<?php

declare(strict_types=1);

use Capell\Core\Contracts\Privacy\PrivacyReviewLedger;
use Capell\Core\Contracts\Privacy\PrivacyReviewResolutions;
use Capell\Core\Data\Privacy\OpenPrivacyReviewData;
use Capell\Core\Data\Privacy\PrivacyReviewCandidateData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Models\Site;
use Capell\Core\Support\Privacy\EmptyPrivacyReviewLedger;
use Capell\Core\Support\Privacy\PrivacySubjectRegistry;
use Capell\Core\Support\Privacy\UnresolvedPrivacyReviewResolutions;
use Capell\Core\Tests\Feature\Privacy\Fixtures\EmailListPrivacyContributor;
use Capell\Core\Tests\Feature\Privacy\Fixtures\ReviewingPrivacyEraser;
use Illuminate\Foundation\Auth\User;

it('collects review candidates only from supporting erasers that report them, in contributor order', function (): void {
    $sharedBooking = new PrivacyReviewCandidateData('bookings', 'booking', 7, PrivacyReviewCandidateData::REASON_FOREIGN_DESCENDANT, siteId: 2);
    $matchedContact = new PrivacyReviewCandidateData('contacts', 'contact', 3, PrivacyReviewCandidateData::REASON_EMAIL_MATCH);

    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new ReviewingPrivacyEraser('contacts', [$matchedContact]))
        ->registerEraser(new ReviewingPrivacyEraser('bookings', [$sharedBooking]))
        ->registerEraser(new ReviewingPrivacyEraser('events', [new PrivacyReviewCandidateData('events', 'ticket', 1, PrivacyReviewCandidateData::REASON_EMAIL_MATCH)], supports: false))
        ->registerEraser(new EmailListPrivacyContributor);

    expect($registry->reviewCandidates(PrivacySubject::forEmail('jane@example.test')))->toBe([$sharedBooking, $matchedContact]);
});

it('returns the external erasures still outstanding from erasers whose data another system holds', function (): void {
    $providerCopy = new PrivacyReviewCandidateData('newsletter', 'subscriber', 12, PrivacyReviewCandidateData::REASON_EXTERNAL_COPY);

    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new ReviewingPrivacyEraser('newsletter', outstanding: [$providerCopy]))
        ->registerEraser(new ReviewingPrivacyEraser('comments'));

    expect($registry->prepareErasure(PrivacySubject::forEmail('jane@example.test')))->toBe([$providerCopy]);
});

it('refuses a review candidate an eraser reports under another contributor key', function (string $method): void {
    $foreign = new PrivacyReviewCandidateData('payments', 'payment', 4, PrivacyReviewCandidateData::REASON_EXTERNAL_COPY);

    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new ReviewingPrivacyEraser('bookings', [$foreign], [$foreign]));

    expect(fn () => $registry->{$method}(PrivacySubject::forEmail('jane@example.test')))
        ->toThrow(LogicException::class, 'The privacy eraser [bookings] reported a review candidate it does not own.');
})->with(['reviewCandidates', 'prepareErasure']);

it('refuses a review result that is not a review candidate', function (): void {
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new ReviewingPrivacyEraser('bookings', [['contributor' => 'bookings']]));

    expect(fn () => $registry->reviewCandidates(PrivacySubject::forEmail('jane@example.test')))
        ->toThrow(LogicException::class);
});

it('says which contributors keep reviewer-retained rows', function (): void {
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new ReviewingPrivacyEraser('bookings', honoursRetainedScopes: true))
        ->registerEraser(new ReviewingPrivacyEraser('contacts'))
        ->registerEraser(new EmailListPrivacyContributor);

    expect($registry->honoursRetainedScopes('bookings'))->toBeTrue()
        ->and($registry->honoursRetainedScopes('contacts'))->toBeFalse()
        ->and($registry->honoursRetainedScopes('mailing-list'))->toBeFalse()
        ->and($registry->honoursRetainedScopes('not-registered'))->toBeFalse();
});

it('treats every candidate as unresolved until a privacy package binds resolutions', function (): void {
    $resolutions = resolve(PrivacyReviewResolutions::class);

    expect($resolutions)->toBeInstanceOf(UnresolvedPrivacyReviewResolutions::class)
        ->and($resolutions->for(PrivacySubject::forEmail('jane@example.test'), 'contacts', 'contact'))->toBe([]);
});

it('has no holds, retained records or resolutions until a privacy package binds a review ledger', function (): void {
    $ledger = resolve(PrivacyReviewLedger::class);
    $review = new OpenPrivacyReviewData(1, 'customer-portal', 'portal-account', 7, null);

    expect($ledger)->toBeInstanceOf(EmptyPrivacyReviewLedger::class)
        ->and($ledger->retained('bookings'))->toBe([])
        ->and($ledger->open('newsletter', 'provider-subscriber', [1, 2], includeDelivered: true, onOpenRequestsOnly: true))->toBe([])
        ->and($ledger->resolve($review, PrivacyReviewLedger::RESOLUTION_ERASED, new User, 'confirmed', 'console'))->toBeFalse()
        ->and($ledger->held(PrivacySubject::forEmail('jane@example.test'), 'newsletter', 'provider-subscriber'))->toBe([]);
});

it('matches an open review to the record whose request holds it', function (): void {
    $owner = (new Site)->forceFill(['id' => 5]);
    $other = (new Site)->forceFill(['id' => 6]);
    $review = new OpenPrivacyReviewData(1, 'customer-portal', 'portal-account', 7, ['type' => $owner->getMorphClass(), 'id' => '5']);
    $emailOnly = new OpenPrivacyReviewData(2, 'customer-portal', 'portal-account', 7, null);

    expect($review->isFor($owner))->toBeTrue()
        ->and($review->isFor($other))->toBeFalse()
        ->and($emailOnly->isFor($owner))->toBeFalse();
});

it('validates review candidate scope', function (string $contributor, string $type, int $id, string $reason): void {
    expect(fn (): PrivacyReviewCandidateData => new PrivacyReviewCandidateData($contributor, $type, $id, $reason))
        ->toThrow(InvalidArgumentException::class, 'Invalid privacy review candidate scope.');
})->with([
    'empty contributor' => ['', 'booking', 1, PrivacyReviewCandidateData::REASON_EMAIL_MATCH],
    'unknown reason' => ['bookings', 'booking', 1, 'because'],
    'no candidate id' => ['bookings', 'booking', 0, PrivacyReviewCandidateData::REASON_EMAIL_MATCH],
    'unscoped with an id' => ['bookings', 'unscoped', 3, PrivacyReviewCandidateData::REASON_UNSCOPED],
    'scoped but unscoped reason' => ['bookings', 'booking', 3, PrivacyReviewCandidateData::REASON_UNSCOPED],
]);

it('accepts an unscoped contributor marker', function (): void {
    $candidate = new PrivacyReviewCandidateData('bookings', 'unscoped', 0, PrivacyReviewCandidateData::REASON_UNSCOPED);

    expect($candidate->candidateId)->toBe(0);
});
