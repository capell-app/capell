<?php

declare(strict_types=1);

namespace Capell\Admin\Actions;

use Capell\Core\Actions\IncrementNameAction;
use Capell\Core\Models\Page;
use Capell\Core\Support\Publishing\PublicationDateGuard;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;

/**
 * @method static Page run(Page $page, array<string, mixed> $data = [])
 */
class ReplicatePageAction
{
    use AsFake;
    use AsObject;

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Page $page, array $data = []): Page
    {
        $page->load('translations');

        $translations = [];
        if (isset($data['translations'])) {
            $translations = $data['translations'];
            unset($data['translations']);
        }

        $model = $page->fresh();
        throw_if($model === null, RuntimeException::class, 'Page could not be refreshed for replication.');

        $model->fill($data);

        $replica = PublicationDateGuard::allow(
            fn (): ?Page => $model->duplicate(),
        );
        throw_if($replica === null, RuntimeException::class, 'Page could not be duplicated.');

        if ($model->isClean('name')) {
            $replica->name = $this->getPageName($model);
        }

        $replica->setAttribute('created_at', now());
        $replica->setAttribute('updated_at', now());

        PublicationDateGuard::allow(
            fn (): bool => $replica->save(),
        );

        if ($translations) {
            foreach ($translations as $translation) {
                $replica->translations()->create($translation);
            }

            $replica->load('translations');
        }

        return $replica;
    }

    private function getPageName(Page $page): string
    {
        $name = IncrementNameAction::run($page->name);

        while ($page::query()->where('name', $name)->exists()) {
            $name = IncrementNameAction::run($name);
        }

        return $name;
    }
}
