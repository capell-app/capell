<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum AdminSurfaceContributionType: string
{
    case Resource = 'resource';
    case Page = 'page';
    case Widget = 'widget';
    case PanelExtender = 'panel_extender';
    case Configurator = 'configurator';
    case SchemaExtender = 'schema_extender';
}
