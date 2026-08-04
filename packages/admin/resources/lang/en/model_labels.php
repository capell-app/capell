<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Model labels
|--------------------------------------------------------------------------
|
| Singular and plural names for the records a Filament resource manages.
|
| These are lower case on purpose. Filament puts them inside sentences
| ("Create blueprint", "No blueprints found", "Delete blueprint") and runs
| Str::ucwords() over them itself when it needs a page heading. A label
| stored capitalised gives "Create Blueprint" mid-sentence.
|
| They live in their own file rather than reusing the `generic`, `form` or
| `navigation` entries, because those are shown as field labels, column
| headers and sidebar links, where the capital is correct.
|
| Keep the plural such that Str::ucwords() of it equals the resource's
| navigation label, or the sidebar link stops naming the page it opens.
| AdminLabelContractTest in capell-app enforces both rules.
|
*/

return [
    'activity' => 'activity log entry',
    'activities' => 'activity log',
    'block_template' => 'block template',
    'block_templates' => 'block templates',
    'blueprint' => 'blueprint',
    'blueprints' => 'blueprints',
    'redirect' => 'redirect',
    'redirects' => 'redirects',
    'role' => 'role',
    'roles' => 'roles',
];
