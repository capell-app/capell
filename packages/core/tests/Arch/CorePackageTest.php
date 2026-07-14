<?php

declare(strict_types=1);

use Capell\Core\Actions\SiteCreatedAction;
use Capell\Core\Actions\SiteReplicatedAction;
use Capell\Core\Console\Commands\MakeExtenderCommand;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Support\Manifest\ManifestValidator;
use Capell\Core\Support\Redirects\PageUrlRedirectResolver;

arch('System package to be standalone')
    ->expect('Capell\Core')
    ->not()->toUse(['Capell\Admin', 'Capell\Frontend', 'Capell\Address', 'Capell\AIOrchestrator', 'Capell\Blog', 'Capell\Hero', 'Capell\Installer', 'Capell\Layout'])
    ->ignoring([
        MakeExtenderCommand::class,
        ManifestValidator::class,
        PageUrlRedirectResolver::class,
        SettingsContract::class,
        SiteCreatedAction::class,
        SiteReplicatedAction::class,
    ]);
