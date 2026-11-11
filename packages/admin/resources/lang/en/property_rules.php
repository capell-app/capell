<?php

declare(strict_types=1);

return [
    'action' => 'Field rules',
    'heading' => 'Set rules for a property',
    'description' => 'Rules decide which values editors, imports and connected tools can save. They apply on every page type that uses this property set, not only this one.',
    'submit' => 'Save rules',
    'saved' => 'Rules saved. New and edited values must now follow them.',
    'property' => 'Property',
    'property_help' => 'Only properties from sets you manage here are listed. Sets installed by a package are managed by that package.',
    'min_help' => 'Leave empty for no lower limit.',
    'max_help' => 'Leave empty for no upper limit.',
    'length_help' => 'Counted in characters, including spaces.',
    'pattern_help' => 'The format the whole value must match, written as a regular expression. For example, [A-Z]{3}-[0-9]{4} accepts ABC-1234. Ask a developer if you are unsure.',
    'unique_help' => 'Stop two pages on the same site from using the same value.',
    'allowed_blueprints_help' => 'Editors can only link to pages of these types. Leave empty to allow any page on the site.',
    'allowed_taxonomies_help' => 'Editors can only choose terms from these taxonomies. Leave empty to allow any term on the site.',
    'allowed_mime_types_help' => 'For example image/png, or image/* for any image. Leave empty to allow any file type.',
    'dimensions_help' => 'In pixels. Images whose size is unknown are refused while a size rule is set.',
    'untouched_breach_title' => 'Saved, but :field breaks a field rule',
    'untouched_breach_body' => ':reason This value was already saved before the rule was added, so your changes went through. Correct it when you can; it must follow the rule the next time it is edited.',
];
