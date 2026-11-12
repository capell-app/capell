<?php

declare(strict_types=1);

namespace Capell\Admin\Tests\Feature\Dashboard\Fixtures;

use Capell\Admin\Contracts\Dashboard\FirstRunTaskContributor;
use Capell\Admin\Data\Dashboard\FirstRunTaskContextData;
use Capell\Admin\Data\Dashboard\FirstRunTaskData;

/** Stands in for a package that adds a "check the contact form" task. */
final class ContactFormFirstRunTaskContributor implements FirstRunTaskContributor
{
    public static ?FirstRunTaskContextData $lastContext = null;

    public function tasks(FirstRunTaskContextData $context): array
    {
        self::$lastContext = $context;

        return [
            new FirstRunTaskData('contact-form', 'Check the contact form', 'Send yourself a test message.', 'Open forms', false, 'https://example.test/admin/forms'),
            // A key the core list already uses, and a repeat of this package's own key, are both ignored.
            new FirstRunTaskData('logo', 'Replace the logo', 'Duplicate of a built-in task.', 'Open', false, null),
            new FirstRunTaskData('contact-form', 'Check the contact form again', 'Duplicate key.', 'Open', false, null),
        ];
    }
}
