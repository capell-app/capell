<?php

declare(strict_types=1);

use Capell\Admin\Actions\Sites\ReconcileWizardSiteDomainsAction;
use Capell\Core\Models\Language;

beforeEach(function (): void {
    $this->english = Language::factory()->createOne(['code' => 'en', 'default' => true]);
    $this->french = Language::factory()->createOne(['code' => 'fr', 'default' => false]);
    $this->german = Language::factory()->createOne(['code' => 'de', 'default' => false]);
});

it('generates a row per selected language on first visit', function (): void {
    $rows = ReconcileWizardSiteDomainsAction::run([], [$this->english->id, $this->french->id], 'https://app.test');

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['url' => 'https://app.test', 'language_id' => $this->english->id, 'default' => true])
        ->and($rows[1])->toMatchArray(['url' => 'https://app.test/fr', 'language_id' => $this->french->id, 'default' => false]);
});

it('keeps edited rows when the person returns to the details step', function (): void {
    $edited = [
        ['url' => 'https://www.example.com/shop', 'language_id' => $this->english->id, 'default' => false, 'use_host_domain' => false],
        ['url' => 'https://fr.example.com', 'language_id' => $this->french->id, 'default' => true, 'use_host_domain' => false],
    ];

    $rows = ReconcileWizardSiteDomainsAction::run($edited, [$this->english->id, $this->french->id], 'https://app.test');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['url'])->toBe('https://www.example.com/shop')
        ->and($rows[0]['default'])->toBeFalse()
        ->and($rows[1]['url'])->toBe('https://fr.example.com')
        ->and($rows[1]['default'])->toBeTrue()
        ->and($rows[1]['use_host_domain'])->toBeFalse();
});

it('adds rows for new languages and drops rows for removed ones', function (): void {
    $edited = [
        ['url' => 'https://www.example.com', 'language_id' => $this->english->id, 'default' => true, 'use_host_domain' => false],
        ['url' => 'https://fr.example.com', 'language_id' => $this->french->id, 'default' => false, 'use_host_domain' => false],
    ];

    $rows = ReconcileWizardSiteDomainsAction::run($edited, [$this->english->id, $this->german->id], 'https://app.test');

    expect(array_column($rows, 'language_id'))->toBe([$this->english->id, $this->german->id])
        ->and($rows[0]['url'])->toBe('https://www.example.com')
        ->and($rows[1]['url'])->toBe('https://app.test/de');
});

it('always leaves exactly one default row, even when the default language is removed', function (): void {
    $edited = [
        ['url' => 'https://www.example.com', 'language_id' => $this->english->id, 'default' => true, 'use_host_domain' => false],
        ['url' => 'https://fr.example.com', 'language_id' => $this->french->id, 'default' => false, 'use_host_domain' => false],
    ];

    $rows = ReconcileWizardSiteDomainsAction::run($edited, [$this->french->id], 'https://app.test');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['default'])->toBeTrue()
        ->and($rows[0]['url'])->toBe('https://fr.example.com');
});

it('ignores duplicate or empty language selections', function (): void {
    $rows = ReconcileWizardSiteDomainsAction::run([], [$this->english->id, null, $this->english->id], 'https://app.test');

    expect($rows)->toHaveCount(1);
});
