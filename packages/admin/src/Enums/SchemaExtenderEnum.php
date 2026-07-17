<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

enum SchemaExtenderEnum: string
{
    case Address = 'capell.address_schema.extenders';

    case Country = 'capell.country_schema.extenders';

    case Language = 'capell.language_schema.extenders';

    case Layout = 'capell.layout_schema.extenders';

    case Navigation = 'capell.navigation_schema.extenders';

    case Page = 'capell.page_schema.extenders';

    case Site = 'capell.site_schema.extenders';

    case Theme = 'capell.theme_schema.extenders';

    case Type = 'capell.type_configurator.extenders';

    case PublishPanel = 'capell.publish_panel.extenders';
}
