<?php

declare(strict_types=1);

use Capell\Core\Actions\GetEditPageResourceUrlAction;
use Capell\Core\Actions\GetResourceFromBlueprintAction;
use Capell\Core\Actions\SiteCreatedAction;
use Capell\Core\Actions\SiteReplicatedAction;
use Capell\Core\Console\Commands\MakeExtenderCommand;
use Capell\Core\Contracts\SettingsContract;
use Capell\Core\Settings\CoreSettings;
use Capell\Core\Support\Redirects\PageUrlRedirectResolver;
use Capell\Core\Support\Settings\SettingsSchemaRegistry;

arch('System package to be standalone')
    ->expect('Capell\Core')
    ->not()->toUse(['Capell\Frontend', 'Capell\Address', 'Capell\AIOrchestrator', 'Capell\Blog', 'Capell\Hero', 'Capell\Layout'])
    ->ignoring([
        CoreSettings::class,
        GetEditPageResourceUrlAction::class,
        GetResourceFromBlueprintAction::class,
        MakeExtenderCommand::class,
        PageUrlRedirectResolver::class,
        SettingsContract::class,
        SettingsSchemaRegistry::class,
        SiteCreatedAction::class,
        SiteReplicatedAction::class,
    ]);
