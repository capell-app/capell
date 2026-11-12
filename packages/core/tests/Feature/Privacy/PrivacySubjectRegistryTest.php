<?php

declare(strict_types=1);
use Capell\Core\Contracts\Privacy\PrivacySubjectExporter;
use Capell\Core\Data\Privacy\PrivacyErasureReceiptData;
use Capell\Core\Data\Privacy\PrivacySubject;
use Capell\Core\Enums\PrivacyErasureMode;
use Capell\Core\Models\Site;
use Capell\Core\Support\Privacy\PrivacySubjectRegistry;
use Capell\Core\Tests\Feature\Privacy\Fixtures\EmailListPrivacyContributor;
use Capell\Core\Tests\Feature\Privacy\Fixtures\MisreportingPrivacyEraser;
use Capell\Core\Tests\Feature\Privacy\Fixtures\SitePrivacyContributor;

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

it('exports each supporting package section and leaves out packages with nothing or no support', function (): void {
    $site = Site::factory()->createOne();
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerExporter(new EmailListPrivacyContributor)
        ->registerExporter(new SitePrivacyContributor);

    expect($registry->export(PrivacySubject::forEmail(' Jane@Example.TEST ')))->toBe(['mailing-list' => ['lists' => ['news', 'offers']]])
        ->and($registry->export(PrivacySubject::forEmail('nobody@example.test')))->toBe([])
        ->and($registry->export(PrivacySubject::forModel($site)))->toBe(['site-notes' => ['site' => ['type' => $site->getMorphClass(), 'id' => (string) $site->getKey()]]]);
});

it('erases through every supporting eraser from outside a request and returns receipts without personal data', function (): void {
    $site = Site::factory()->createOne();
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new SitePrivacyContributor)
        ->registerEraser(new EmailListPrivacyContributor);

    $receipts = $registry->erase(PrivacySubject::forModel($site, 'jane@example.test'), PrivacyErasureMode::Delete);

    expect(array_map(static fn (PrivacyErasureReceiptData $receipt): array => $receipt->toArray(), $receipts))->toBe([
        ['contributor' => 'mailing-list', 'mode' => 'delete', 'affected_records' => 2, 'erased_files' => [], 'suppression_marker_retained' => true],
        ['contributor' => 'site-notes', 'mode' => 'delete', 'affected_records' => 1, 'erased_files' => ['local:site-notes/' . $site->getKey() . '.txt'], 'suppression_marker_retained' => false],
    ])
        ->and(EmailListPrivacyContributor::$entries)->toBe([])
        ->and(json_encode(array_map(static fn (PrivacyErasureReceiptData $receipt): array => $receipt->toArray(), $receipts), JSON_THROW_ON_ERROR))->not->toContain('jane@example.test');
});

it('says which packages keep a suppression marker before erasure runs', function (): void {
    $registry = resolve(PrivacySubjectRegistry::class)
        ->registerEraser(new SitePrivacyContributor)
        ->registerEraser(new EmailListPrivacyContributor);

    expect($registry->suppressionMarkerContributors(PrivacySubject::forEmail('jane@example.test')))->toBe(['mailing-list'])
        ->and(EmailListPrivacyContributor::$entries)->not->toBe([]);
});

it('refuses an eraser receipt for another package or an undeclared suppression marker', function (MisreportingPrivacyEraser $eraser): void {
    $registry = resolve(PrivacySubjectRegistry::class)->registerEraser($eraser);

    expect(fn (): array => $registry->erase(PrivacySubject::forEmail('jane@example.test'), PrivacyErasureMode::Anonymize))->toThrow(LogicException::class);
})->with([
    'another contributor' => fn (): MisreportingPrivacyEraser => new MisreportingPrivacyEraser,
    'undeclared marker' => fn (): MisreportingPrivacyEraser => new MisreportingPrivacyEraser('misreporting', true),
]);

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

        public function export(PrivacySubject $subject): array
        {
            return ['stolen' => true];
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

        public function export(PrivacySubject $subject): array
        {
            return [];
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

it('refuses a receipt that would carry file contents or a negative count', function (): void {
    expect(fn (): PrivacyErasureReceiptData => new PrivacyErasureReceiptData('x', PrivacyErasureMode::Delete, -1))->toThrow(InvalidArgumentException::class)
        ->and(fn (): PrivacyErasureReceiptData => new PrivacyErasureReceiptData('x', PrivacyErasureMode::Delete, 0, ['jane@example.test, 1 Main Street']))->toThrow(InvalidArgumentException::class);
});
