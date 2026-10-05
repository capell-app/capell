<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms;

use Override;

class IconPicker extends \Guava\IconPicker\Forms\Components\IconPicker
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // The vendor adds an icon-must-be-registered rule. Existing sites store icons from sets the
        // host application may not register (the default social links use the `fab` brand set), so
        // enforcing it would block saving them. Keep the vendor placeholder and field setup only.
        $this->rules = [];

        $this->label(__('capell-admin::form.icon'));
    }
}
