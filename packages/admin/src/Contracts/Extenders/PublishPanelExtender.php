<?php

declare(strict_types=1);

namespace Capell\Admin\Contracts\Extenders;

use Capell\Admin\Data\PagePublishStateData;
use Capell\Admin\Enums\SchemaExtenderEnum;
use Illuminate\Contracts\View\View;

/**
 * Allows packages to inject additional sections into the PublishStatusPanel.
 * Register with: $this->app->tag([MyExtender::class], PublishPanelExtender::TAG)
 */
interface PublishPanelExtender
{
    public const string TAG = SchemaExtenderEnum::PublishPanel->value;

    /**
     * Return a rendered HTML string or View to append to the panel body,
     * or null to add nothing.
     */
    public function extendPanel(PagePublishStateData $state): View|string|null;
}
