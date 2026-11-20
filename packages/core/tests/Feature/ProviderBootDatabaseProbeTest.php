<?php

declare(strict_types=1);

use Capell\Core\Database\Factories\MediaFactory;
use Capell\Core\Enums\MediaCollectionEnum;
use Capell\Core\Models\Site;
use Capell\Tests\Support\BootQueryLog;
use Capell\Tests\Support\RecordsBootQueries;
use Illuminate\Notifications\Messages\MailMessage;

pest()->use(RecordsBootQueries::class)->in(__FILE__);

it('boots every service provider without touching the database', function (): void {
    // Bug caught: a provider probing the schema at boot (Schema::hasTable) makes
    // `config:cache`, `package:discover` or `route:list` need a live database in a build step.
    expect(BootQueryLog::$queries)->toBe([]);
});

it('still applies the default site logo to mail once a markdown header renders', function (): void {
    // Bug caught: moving the probe off boot must not drop the branded mail logo.
    config(['mail.markdown.logo' => null]);
    $site = Site::factory()->default()->create(['meta' => ['mail' => ['use_site_logo' => true]]]);
    $logo = MediaFactory::new([
        'model_type' => resolve(Site::class)->getMorphClass(),
        'model_id' => $site->id,
        'collection_name' => MediaCollectionEnum::Logo,
    ])->createOne();

    $html = (string) (new MailMessage)->greeting('Hello')->render();

    expect($html)->toContain($logo->getFullUrl());
});
