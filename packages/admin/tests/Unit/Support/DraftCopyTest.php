<?php

declare(strict_types=1);

it('explains the save as draft action and draft location choice', function (): void {
    expect(__('capell-admin::button.save_as_draft'))->toBe('Save as draft')
        ->and(__('capell-admin::button.save_as_draft_tooltip'))
        ->toBe('Save current changes without publishing them')
        ->and(__('capell-admin::message.save_as_draft_description'))
        ->toBe('Save the current page changes without publishing them. Choose the draft location to update an existing draft workspace or create a separate draft for this page.')
        ->and(__('capell-admin::message.save_as_draft_option_new'))
        ->toBe('Create a separate draft');
});
