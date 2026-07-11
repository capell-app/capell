<?php

declare(strict_types=1);

namespace Capell\Core\Actions;

use Capell\Admin\Enums\ResourceEnum;
use Capell\Core\Contracts\Pageable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsObject;

class GetEditPageResourceUrlAction
{
    use AsObject;

    /**
     * @template TDeclaringModel of Model
     *
     * @param  int|Pageable<TDeclaringModel>  $page
     */
    public function handle(int|Pageable $page, ?string $type = null): ?string
    {
        if (is_int($page)) {
            throw_if($type === null, InvalidArgumentException::class, 'Page type is required when resolving a page by id.');

            $modelClass = Relation::getMorphedModel($type);
            // Validate the resolved model class explicitly so static analysis understands the control flow
            throw_if($modelClass === null || ! is_subclass_of($modelClass, Pageable::class), InvalidArgumentException::class, 'Invalid page type: ' . $type);
            $pageId = $page;
            /** @var (Pageable<Model>&Model)|null $page */
            $page = $modelClass::query()->find($pageId);
            // Use the original id in the message and avoid concatenating an object
            throw_if($page === null, InvalidArgumentException::class, 'Page not found: ' . $pageId);
        }

        if (app()->bound('filament')) {
            try {
                $resourceClass = GetResourceFromBlueprintAction::run(ResourceEnum::Page, $page->blueprint);
            } catch (InvalidArgumentException) {
                $resourceClass = null;
            }

            if ($resourceClass !== null && class_exists($resourceClass)) {
                return $resourceClass::getUrl('edit', ['record' => $page]);
            }
        }

        // Fallback: check for a named route (e.g., 'admin.pages.edit')
        $routeName = 'filament.admin.resources.pages.edit';
        if (Route::has($routeName)) {
            return route($routeName, ['record' => $page->getKey()]);
        }

        return null;
    }
}
