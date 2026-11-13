<?php

declare(strict_types=1);

namespace Capell\Admin\Support;

/**
 * Plain words editors see in place of technical ones. Editor screens use
 * `term`; `instead_of` names the word it replaces so checks can keep it out.
 */
final class AdminGlossary
{
    /** @return list<array{term: string, instead_of: string, meaning: string}> */
    public static function terms(): array
    {
        return [
            [
                'term' => (string) __('capell-admin::glossary.page_address.term'),
                'instead_of' => (string) __('capell-admin::glossary.page_address.instead_of'),
                'meaning' => (string) __('capell-admin::glossary.page_address.meaning'),
            ],
            [
                'term' => (string) __('capell-admin::glossary.page_type.term'),
                'instead_of' => (string) __('capell-admin::glossary.page_type.instead_of'),
                'meaning' => (string) __('capell-admin::glossary.page_type.meaning'),
            ],
            [
                'term' => (string) __('capell-admin::glossary.language.term'),
                'instead_of' => (string) __('capell-admin::glossary.language.instead_of'),
                'meaning' => (string) __('capell-admin::glossary.language.meaning'),
            ],
            [
                'term' => (string) __('capell-admin::glossary.main_address.term'),
                'instead_of' => (string) __('capell-admin::glossary.main_address.instead_of'),
                'meaning' => (string) __('capell-admin::glossary.main_address.meaning'),
            ],
            [
                'term' => (string) __('capell-admin::glossary.unsaved_changes.term'),
                'instead_of' => (string) __('capell-admin::glossary.unsaved_changes.instead_of'),
                'meaning' => (string) __('capell-admin::glossary.unsaved_changes.meaning'),
            ],
            [
                'term' => (string) __('capell-admin::glossary.blocks.term'),
                'instead_of' => (string) __('capell-admin::glossary.blocks.instead_of'),
                'meaning' => (string) __('capell-admin::glossary.blocks.meaning'),
            ],
            [
                'term' => (string) __('capell-admin::glossary.draft.term'),
                'instead_of' => (string) __('capell-admin::glossary.draft.instead_of'),
                'meaning' => (string) __('capell-admin::glossary.draft.meaning'),
            ],
        ];
    }
}
