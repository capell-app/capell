<?php

declare(strict_types=1);

namespace Capell\Admin\Filament\Components\Forms\Site;

use Capell\Core\Models\Language;
use Capell\Core\Models\Site;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Illuminate\View\View;
use RuntimeException;

class DomainsRepeater extends Repeater
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('capell-admin::form.site_domains'))
            ->addActionLabel(__('capell-admin::button.add_site_domain'))
            ->hiddenLabel()
            ->required()
            ->defaultItems(1)
            ->minItems(1)
            ->columns(1)
            ->deletable(fn (?array $state): bool => count($state ?? []) > 1)
            ->deleteAction(fn (Action $action): Action => $action->requiresConfirmation())
            ->reorderable()
            ->cloneable()
            ->cloneAction(
                fn (Action $action): Action => $action->action($this->cloneSiteAction(...)),
            )
            ->itemLabel(
                function (int|string $uuid, array $state, ?Site $record): View|string {
                    $id = $state['id'] ?? null;
                    if (in_array($id, [null, '', '0', 0], true)) {
                        return __('capell-admin::form.new_site_domain');
                    }

                    /** @var view-string $itemLabelView */
                    $itemLabelView = 'capell-admin::components.forms.site.site_domain_item_label';

                    return view(
                        $itemLabelView,
                        [
                            'language' => $this->getRecordLanguage($record, $state['language_id'] ?? null),
                            'url' => $state['url'],
                        ],
                    );
                },
            )
            ->extraItemActions([
                Action::make('openSiteDomain')
                    ->tooltip(__('capell-admin::generic.open_site_domain'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(
                        fn (array $arguments, Repeater $component): ?string => $component->getRawItemState((string) $arguments['item'])['url'] ?? null,
                        shouldOpenInNewTab: true,
                    )
                    ->hidden(function (string $operation, array $arguments, Repeater $component): bool {
                        if (in_array($operation, ['create', 'createOption'], true)) {
                            return true;
                        }

                        return blank($component->getRawItemState((string) $arguments['item'])['url']);
                    }),
            ])
            ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                $host = $data['domain'] ?? request()->getHost();
                $data['url'] = sprintf('%s://%s%s', $data['scheme'], $host, $data['path']);
                $data['use_host_domain'] = $data['domain'] === null;

                return $data;
            })
            ->mutateRelationshipDataBeforeCreateUsing($this->getMutateRelationshipDataBeforeCreateOrSave(...))
            ->mutateRelationshipDataBeforeSaveUsing($this->getMutateRelationshipDataBeforeCreateOrSave(...));
    }

    public static function getDefaultName(): ?string
    {
        return 'site_domains';
    }

    /** @param array{item: int|string} $arguments */
    private function cloneSiteAction(array $arguments, Repeater $component): void
    {
        $newUuid = $component->generateUuid();

        $items = $component->getState();

        $item = $this->modifyItemBeforeClone($items[$arguments['item']]);

        if (! in_array($newUuid, [null, '', '0'], true)) {
            $items[$newUuid] = $item;
        } else {
            $items[] = $item;
        }

        $component->state($items);

        $component->collapsed(false, shouldMakeComponentCollapsible: false);

        $component->callAfterStateUpdated();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function getMutateRelationshipDataBeforeCreateOrSave(array $data): array
    {
        $urlParts = parse_url((string) $data['url']);

        throw_if($urlParts === false, RuntimeException::class, 'Unable to parse site domain URL.');

        unset($data['url']);

        $data['scheme'] = $urlParts['scheme'] ?? null;
        $data['domain'] = ($data['use_host_domain'] ?? false) === true ? null : ($urlParts['host'] ?? null);
        $data['path'] = isset($urlParts['path']) ? in_array(mb_rtrim($urlParts['path'], '/'), ['', '0'], true) ? null : mb_rtrim($urlParts['path'], '/') : null;

        unset($data['use_host_domain']);

        $data['default'] ??= false;
        $data['status'] ??= true;

        return $data;
    }

    private function getRecordLanguage(?Site $record, ?int $languageId): ?Language
    {
        if ($languageId === null || $languageId === 0) {
            return null;
        }

        if ($record?->relationLoaded('siteDomains')) {
            $domain = $record->siteDomains->firstWhere('language_id', $languageId);

            if ($domain !== null) {
                if (! $domain->relationLoaded('language')) {
                    $domain->load('language');
                }

                $language = $domain->language;

                if ($language !== null) {
                    return $language;
                }
            }
        }

        return Language::query()->find($languageId);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function modifyItemBeforeClone(array $item): array
    {
        if (isset($item['id'])) {
            unset($item['id']);
        }

        $item['default'] = false;

        return $item;
    }
}
