<?php

declare(strict_types=1);
use Capell\Core\Contracts\Privacy\PrivacySubjectExporter;
use Capell\Core\Data\Privacy\PrivacyErasureFailureData;
use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacyExportSectionData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Capell\Core\Models\Site;
use Capell\Core\Support\Privacy\PrivacySubjectRegistry;
use Capell\Core\Tests\Feature\Privacy\Fixtures\EmailListPrivacyContributor;
use Capell\Core\Tests\Feature\Privacy\Fixtures\MisreportingPrivacyEraser;
use Capell\Core\Tests\Feature\Privacy\Fixtures\SitePrivacyContributor;
use Capell\Core\Tests\Feature\Privacy\Fixtures\ThrowingPrivacyEraser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    EmailListPrivacyContributor::$entries = ['jane@example.test' => ['news', 'offers']];
});

it('collects exporters and erasers that packages tag, without naming the privacy package', function (): void {
    app()->tag([EmailListPrivacyContributor::class, SitePrivacyContributor::class], PrivacySubjectRegistry::EXPORTER_TAG);
    app()->tag([EmailListPrivacyContributor::class, SitePrivacyContributor::class], PrivacySubjectRegistry::ERASER_TAG);

    $registry = resolve(PrivacySubjectRegistry::class);

    expect(array_keys($registry->exporters()))->toEqualCanonicalizing(['mailing-list', 'site-notes'])
        ->and(array_keys($registry->erasers()))->toEqualCanonicalizing(['mailing-list', 'site-notes'])
        ->and(resolve(PrivacySubjectRegistry::class))->toBe($registry);
});

it('exports each supporting package section with its files and leaves out packages with nothing or no support', function (): void {
    $site = Site::factory()->createOne();
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerExporter(new EmailListPrivacyContributor)
        ->registerExporter(new SitePrivacyContributor);

    $byEmail = $registry->export(PrivacySubject::forEmail(' Jane@Example.TEST '));
    $bySite = $registry->export(PrivacySubject::forModel($site));

    expect(array_keys($byEmail))->toBe(['mailing-list'])
        ->and($byEmail['mailing-list']->data)->toBe(['lists' => ['news', 'offers']])
        ->and($registry->export(PrivacySubject::forEmail('nobody@example.test')))->toBe([])
        ->and(array_keys($bySite))->toBe(['site-notes'])
        ->and($bySite['site-notes']->data)->toBe(['site' => ['type' => $site->getMorphClass(), 'id' => (string) $site->getKey()]])
        ->and($bySite['site-notes']->files)->toBe(['local:site notes/' . $site->getKey() . '/My CV.pdf']);
});

it('erases through every supporting eraser from outside a request and returns receipts without personal data', function (): void {
    $site = Site::factory()->createOne();
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new SitePrivacyContributor)
        ->registerEraser(new EmailListPrivacyContributor);

    $result = $registry->erase(PrivacySubject::forModel($site, 'jane@example.test'), PrivacyErasureMode::Delete);

    expect($result->isComplete())->toBeTrue()
        ->and(array_map(static fn (PrivacyErasureReceiptData $receipt): array => $receipt->toArray(), $result->receipts))->toBe([
            ['contributor' => 'mailing-list', 'mode' => 'delete', 'affected_records' => 2, 'erased_files' => [], 'suppression_marker_retained' => true],
            // Asked to delete, the package anonymised the records it must keep, and says so.
            ['contributor' => 'site-notes', 'mode' => 'anonymize', 'affected_records' => 1, 'erased_files' => ['local:site notes/' . $site->getKey() . '/My CV.pdf'], 'suppression_marker_retained' => false],
        ])
        ->and(EmailListPrivacyContributor::$entries)->toBe([])
        ->and(json_encode(array_map(static fn (PrivacyErasureReceiptData $receipt): array => $receipt->toArray(), $result->receipts), JSON_THROW_ON_ERROR))->not->toContain('jane@example.test');
});

it('keeps the other packages receipts and runs the rest when one eraser fails', function (): void {
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new SitePrivacyContributor)
        ->registerEraser(new ThrowingPrivacyEraser)
        ->registerEraser(new EmailListPrivacyContributor);

    $result = $registry->erase(PrivacySubject::forEmail('jane@example.test'), PrivacyErasureMode::Delete);

    expect($result->isComplete())->toBeFalse()
        ->and(array_map(static fn (PrivacyErasureReceiptData $receipt): string => $receipt->contributor, $result->receipts))->toBe(['mailing-list'])
        ->and(array_map(static fn (PrivacyErasureFailureData $failure): array => $failure->toArray(), $result->failures))->toBe([
            ['contributor' => 'broken-store', 'code' => PrivacyErasureFailureData::CODE_ERASER_FAILED, 'exception_class' => RuntimeException::class, 'rejected_receipt' => null],
        ])
        ->and(EmailListPrivacyContributor::$entries)->toBe([])
        ->and(json_encode(array_map(static fn (PrivacyErasureFailureData $failure): array => $failure->toArray(), $result->failures), JSON_THROW_ON_ERROR))->not->toContain('jane@example.test');
});

it('says which packages keep a suppression marker before erasure runs', function (): void {
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new SitePrivacyContributor)
        ->registerEraser(new EmailListPrivacyContributor);

    expect($registry->suppressionMarkerContributors(PrivacySubject::forEmail('jane@example.test')))->toBe(['mailing-list'])
        ->and(EmailListPrivacyContributor::$entries)->not->toBe([]);
});

it('records an eraser receipt for another package or an undeclared suppression marker as a failure', function (string $case, string $code): void {
    $eraser = $case === 'marker' ? new MisreportingPrivacyEraser('misreporting', true) : new MisreportingPrivacyEraser;
    $registry = resolve(PrivacySubjectRegistry::class)->registerEraser($eraser)->registerEraser(new EmailListPrivacyContributor);

    $result = $registry->erase(PrivacySubject::forEmail('jane@example.test'), PrivacyErasureMode::Anonymize);
    // The receipt the package returned is kept, so an operator can see what it says it erased.
    $rejected = ['contributor' => $case === 'marker' ? 'misreporting' : 'someone-else', 'mode' => 'anonymize', 'affected_records' => 0, 'erased_files' => [], 'suppression_marker_retained' => $case === 'marker'];

    expect($result->isComplete())->toBeFalse()
        ->and(array_map(static fn (PrivacyErasureFailureData $failure): array => $failure->toArray(), $result->failures))->toBe([['contributor' => 'misreporting', 'code' => $code, 'exception_class' => null, 'rejected_receipt' => $rejected]])
        ->and(array_map(static fn (PrivacyErasureReceiptData $receipt): string => $receipt->contributor, $result->receipts))->toBe(['mailing-list']);
})->with([
    'another contributor' => ['contributor', PrivacyErasureFailureData::CODE_RECEIPT_CONTRIBUTOR],
    'undeclared marker' => ['marker', PrivacyErasureFailureData::CODE_UNDECLARED_MARKER],
]);

it('matches a subject email case-insensitively in a package query', function (): void {
    Schema::create('privacy_test_entries', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
    });
    DB::table('privacy_test_entries')->insert([['email' => 'Jane@Example.TEST'], ['email' => 'jane@example.test'], ['email' => 'john@example.test']]);

    $byEmail = DB::table('privacy_test_entries');
    PrivacySubject::forEmail('JANE@example.test')->whereEmail($byEmail, 'email');
    $withoutEmail = DB::table('privacy_test_entries');
    PrivacySubject::forModel(Site::factory()->createOne())->whereEmail($withoutEmail, 'email');

    expect($byEmail->count())->toBe(2)
        ->and($withoutEmail->count())->toBe(0);
});

it('refuses to serialise a subject into a queue payload', function (): void {
    expect(fn (): string => serialize(PrivacySubject::forEmail('jane@example.test')))->toThrow(LogicException::class, 'privacy request id');
});

it('refuses two different packages with the same key and malformed keys', function (): void {
    $registry = resolve(PrivacySubjectRegistry::class)->registerExporter(new EmailListPrivacyContributor);

    $impostor = new class implements PrivacySubjectExporter
    {
        public function contributor(): string
        {
            return 'mailing-list';
        }

        public function supports(PrivacySubject $subject): bool
        {
            return true;
        }

        public function export(PrivacySubject $subject): PrivacyExportSectionData
        {
            return new PrivacyExportSectionData(['stolen' => true]);
        }
    };

    $badKey = new class implements PrivacySubjectExporter
    {
        public function contributor(): string
        {
            return 'Bad Key';
        }

        public function supports(PrivacySubject $subject): bool
        {
            return true;
        }

        public function export(PrivacySubject $subject): PrivacyExportSectionData
        {
            return new PrivacyExportSectionData;
        }
    };

    expect(fn (): PrivacySubjectRegistry => $registry->registerExporter($impostor))->toThrow(LogicException::class, 'Two privacy exporters')
        ->and(fn (): PrivacySubjectRegistry => $registry->registerExporter($badKey))->toThrow(LogicException::class, 'lower case');

    $registry->registerExporter(new EmailListPrivacyContributor);
    expect(array_keys($registry->exporters()))->toBe(['mailing-list']);
});

it('refuses a subject without a stored record or a valid email address', function (string $case): void {
    $make = match ($case) {
        'unsaved record' => fn (): PrivacySubject => PrivacySubject::forModel(new Site),
        'invalid email' => fn (): PrivacySubject => PrivacySubject::forEmail('not-an-email'),
        default => throw new InvalidArgumentException('Unknown case.'),
    };

    expect($make)->toThrow(InvalidArgumentException::class);
})->with(['unsaved record', 'invalid email']);

it('accepts stored file paths with spaces and refuses control characters or a negative count', function (): void {
    expect(new PrivacyErasureReceiptData('x', PrivacyErasureMode::Delete, 1, ['public:uploads/My CV (final).pdf'])->erasedFiles)->toBe(['public:uploads/My CV (final).pdf'])
        ->and(fn (): PrivacyErasureReceiptData => new PrivacyErasureReceiptData('x', PrivacyErasureMode::Delete, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn (): PrivacyErasureReceiptData => new PrivacyErasureReceiptData('x', PrivacyErasureMode::Delete, 0, ["public:uploads/a\nb.pdf"]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): PrivacyErasureReceiptData => new PrivacyErasureReceiptData('x', PrivacyErasureMode::Delete, 0, ['no-disk-separator']))->toThrow(InvalidArgumentException::class)
        ->and(fn (): PrivacyExportSectionData => new PrivacyExportSectionData([], ["local:a\tb"]))->toThrow(InvalidArgumentException::class);
});

it('reports a failing eraser to the exception handler and still records the failure', function (): void {
    Exceptions::fake();
    $registry = resolve(PrivacySubjectRegistry::class)->registerEraser(new ThrowingPrivacyEraser);

    $result = $registry->erase(PrivacySubject::forEmail('jane@example.test'), PrivacyErasureMode::Delete);

    expect($result->failures)->toHaveCount(1);
    Exceptions::assertReported(RuntimeException::class);
});

it('erases through only the named packages so a failed erasure can be run again for those alone', function (): void {
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new ThrowingPrivacyEraser)
        ->registerEraser(new EmailListPrivacyContributor);

    $result = $registry->erase(PrivacySubject::forEmail('jane@example.test'), PrivacyErasureMode::Delete, only: ['mailing-list']);
    $missing = $registry->erase(PrivacySubject::forEmail('jane@example.test'), PrivacyErasureMode::Delete, only: ['uninstalled-package']);

    expect($result->isComplete())->toBeTrue()
        ->and(array_map(static fn (PrivacyErasureReceiptData $receipt): string => $receipt->contributor, $result->receipts))->toBe(['mailing-list'])
        ->and(EmailListPrivacyContributor::$entries)->toBe([])
        // A named package that is no longer registered is a failure, never a silent success.
        ->and(array_map(static fn (PrivacyErasureFailureData $failure): array => $failure->toArray(), $missing->failures))->toBe([
            ['contributor' => 'uninstalled-package', 'code' => PrivacyErasureFailureData::CODE_CONTRIBUTOR_MISSING, 'exception_class' => null, 'rejected_receipt' => null],
        ]);
});

it('says whether any package handled the subject', function (): void {
    $registry = resolve(PrivacySubjectRegistry::class)->registerEraser(new EmailListPrivacyContributor);
    $site = Site::factory()->createOne();

    expect($registry->erase(PrivacySubject::forModel($site), PrivacyErasureMode::Delete)->ranAny())->toBeFalse()
        ->and($registry->erase(PrivacySubject::forEmail('jane@example.test'), PrivacyErasureMode::Delete)->ranAny())->toBeTrue()
        ->and(resolve(PrivacySubjectRegistry::class)->registerEraser(new ThrowingPrivacyEraser)->erase(PrivacySubject::forModel($site), PrivacyErasureMode::Delete)->ranAny())->toBeTrue();
});

it('keeps the subject match inside its own group when the query already has an or condition', function (string $method): void {
    Schema::create('privacy_test_grouped', function (Blueprint $table): void {
        $table->id();
        $table->string('email');
        $table->string('email_hash');
        $table->string('status');
    });
    $subject = PrivacySubject::forEmail('jane@example.test');
    DB::table('privacy_test_grouped')->insert([
        ['email' => 'jane@example.test', 'email_hash' => $subject->emailHash(), 'status' => 'archived'],
        // Ungrouped, "active OR (archived AND subject)" would return John's row too.
        ['email' => 'john@example.test', 'email_hash' => 'other', 'status' => 'active'],
    ]);

    $query = DB::table('privacy_test_grouped')->where('status', 'active')->orWhere('status', 'archived');
    $method === 'email' ? $subject->whereEmail($query, 'email') : $subject->whereEmailHash($query, 'email_hash');
    $withoutEmail = DB::table('privacy_test_grouped')->where('status', 'active')->orWhere('status', 'archived');
    PrivacySubject::forModel(Site::factory()->createOne())->whereEmail($withoutEmail, 'email');

    expect($query->pluck('email')->all())->toBe(['jane@example.test'])
        ->and($withoutEmail->count())->toBe(0);
})->with(['email', 'hash']);
