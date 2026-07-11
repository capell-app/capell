<?php

declare(strict_types=1);

namespace Capell\Admin\Enums;

use Filament\Support\Contracts\HasLabel;

enum AdminFormActionPositionEnum: string implements HasLabel
{
    case AboveForm = 'above_form';

    case BelowForm = 'below_form';

    public function getLabel(): string
    {
        return match ($this) {
            self::AboveForm => __('capell-admin::form.admin_form_action_position_above_form'),
            self::BelowForm => __('capell-admin::form.admin_form_action_position_below_form'),
        };
    }
}
