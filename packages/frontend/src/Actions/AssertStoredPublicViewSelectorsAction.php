<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Actions\ValidateStoredViewSelectorsAction;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Theme;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AssertStoredPublicViewSelectorsAction
{
    use AsFake;
    use AsObject;

    public function handle(Layout|Theme $record): void
    {
        try {
            ValidateStoredViewSelectorsAction::run($record);
        } catch (ValidationException) {
            // Public errors must not disclose authoring field paths or selectors.
            throw new NotFoundHttpException;
        }
    }
}
