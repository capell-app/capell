<?php

declare(strict_types=1);

use Capell\Core\Data\Capabilities\FormReferenceData;
use Capell\Core\Data\Capabilities\FormSubmissionSourceData;
use Capell\Core\Data\Capabilities\SubmittedFormData;
use Capell\Core\Events\Capabilities\FormSubmissionReceived;
use Capell\Core\Models\Language;
use Carbon\CarbonImmutable;

it('resolves the stored record a submission came from, and survives queue serialisation', function (): void {
    $record = Language::factory()->create();
    $event = new FormSubmissionReceived(new SubmittedFormData(
        form: new FormReferenceData(id: 4, key: 'contact', name: 'Contact', siteId: 1),
        submissionId: 9,
        values: ['email' => 'a@example.test'],
        submittedAt: CarbonImmutable::now(),
        source: FormSubmissionSourceData::forModel($record),
        referer: 'https://example.test/pricing',
        userAgent: 'Capell Test Browser',
    ));

    $queued = unserialize(serialize($event));

    expect($queued->submission->source?->resolve()?->is($record))->toBeTrue()
        ->and($queued->submission->referer)->toBe('https://example.test/pricing')
        ->and($queued->submission->userAgent)->toBe('Capell Test Browser');
});

it('resolves nothing for an unknown type or a deleted row', function (): void {
    $record = Language::factory()->create();
    $source = FormSubmissionSourceData::forModel($record);
    $record->delete();

    expect(new FormSubmissionSourceData('Missing\\Submission', 1)->resolve())->toBeNull()
        ->and(new FormSubmissionSourceData(stdClass::class, 1)->resolve())->toBeNull()
        ->and($source?->resolve())->toBeNull();
});

it('leaves the source empty for an unstored submission', function (): void {
    $data = new SubmittedFormData(new FormReferenceData(4, 'contact', 'Contact'), null, [], CarbonImmutable::now());

    expect($data->source)->toBeNull()->and($data->referer)->toBeNull()->and($data->userAgent)->toBeNull();
});
