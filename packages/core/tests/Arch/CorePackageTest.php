<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteCreatedAction;
use Capell\Core\Actions\SiteReplicatedAction;
use Capell\Core\Console\Commands\MakeExtenderCommand;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\Redirects\PageUrlRedirectResolver;
use Capell\Tests\Support\ArchitectureDependencies;

arch('keeps the Core package standalone', function (): void {
    ArchitectureDependencies::assertNoForbiddenUses(['Capell\Core'], [
        'Capell\Admin',
        'Capell\Frontend',
        'Capell\Address',
        'Capell\AIOrchestrator',
        'Capell\Blog',
        'Capell\Hero',
        'Capell\Installer',
        'Capell\Layout',
    ], [
        MakeExtenderCommand::class,
        ManifestValidator::class,
        PageUrlRedirectResolver::class,
        SettingsContract::class,
        SiteCreatedAction::class,
        SiteReplicatedAction::class,
    ]);
});
