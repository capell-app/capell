<?php

declare(strict_types=1);

use Capell\Core\Support\Accessibility\PublicAccessibilityContract;

it('passes a well-formed public section fragment', function (): void {
    $fragment = <<<'HTML'
    <section id="newsletter">
        <h2>Subscribe</h2>
        <h3>Weekly digest</h3>
        <label for="newsletter-email">Email address</label>
        <input id="newsletter-email" name="email" type="email" aria-describedby="newsletter-note" />
        <p id="newsletter-note">One message a week.</p>
        <button type="submit">Sign up</button>
        <img src="/hero.avif" alt="The studio at dusk" />
        <img src="/rule.svg" alt="" />
    </section>
    HTML;

    expect(new PublicAccessibilityContract()->inspectFragment($fragment))->toBe([]);
});

it('treats an empty fragment as vacuously compliant', function (): void {
    expect(new PublicAccessibilityContract()->inspectFragment('   '))->toBe([]);
});

it('requires every image to declare alt, accepting an explicit empty alt as decorative', function (): void {
    $contract = new PublicAccessibilityContract;

    expect($contract->inspectFragment('<img src="/photo.avif" />'))
        ->toHaveCount(1)
        ->and($contract->inspectFragment('<img src="/photo.avif" />')[0])
        ->toContain('missing an alt attribute')
        ->and($contract->inspectFragment('<img src="/rule.svg" alt="" />'))
        ->toBe([])
        ->and($contract->inspectFragment('<img src="/photo.avif" alt="A wide shot" />'))
        ->toBe([]);
});

it('requires an accessible name on interactive controls and nameless structural roles', function (): void {
    $contract = new PublicAccessibilityContract;

    $nameless = [
        '<button class="close"><svg><path d="M0 0" /></svg></button>',
        '<a href="/about"><svg><path d="M0 0" /></svg></a>',
        '<div role="button" tabindex="0"></div>',
        '<div role="img"></div>',
        '<a href="/gallery"><img src="/thumb.avif" alt="" /></a>',
    ];

    foreach ($nameless as $fragment) {
        expect($contract->inspectFragment($fragment))->toHaveCount(1)
            ->and($contract->inspectFragment($fragment)[0])->toContain('requires an accessible name');
    }

    $named = [
        '<button aria-label="Close the dialog"><svg><path d="M0 0" /></svg></button>',
        '<a href="/about">About the studio</a>',
        '<a href="/gallery"><img src="/thumb.avif" alt="Open the gallery" /></a>',
        '<button title="Play"><svg><path d="M0 0" /></svg></button>',
        '<div role="img" aria-label="Map of the city"></div>',
        '<button><svg><title>Play</title><path d="M0 0" /></svg></button>',
        '<a>Anchors without href are not controls</a>',
        '<button aria-hidden="true" tabindex="-1"></button>',
        '<span role="presentation"></span>',
    ];

    foreach ($named as $fragment) {
        expect($contract->inspectFragment($fragment))->toBe([]);
    }
});

it('accepts the user-agent default label on submit and reset buttons but not on image inputs', function (): void {
    $contract = new PublicAccessibilityContract;

    expect($contract->inspectFragment('<input type="submit" />'))->toBe([])
        ->and($contract->inspectFragment('<input type="reset" />'))->toBe([])
        ->and($contract->inspectFragment('<input type="button" value="Apply" />'))->toBe([])
        ->and($contract->inspectFragment('<input type="button" />'))->toHaveCount(1)
        ->and($contract->inspectFragment('<input type="image" src="/go.avif" />'))->toHaveCount(1)
        ->and($contract->inspectFragment('<input type="image" src="/go.avif" alt="Search" />'))->toBe([]);
});

it('requires data-entry controls to be associated with a label and rejects placeholder-only labelling', function (): void {
    $contract = new PublicAccessibilityContract;

    expect($contract->inspectFragment('<input type="text" name="query" placeholder="Search" />'))
        ->toHaveCount(1)
        ->and($contract->inspectFragment('<input type="text" name="query" placeholder="Search" />')[0])
        ->toContain('has no associated label')
        ->and($contract->inspectFragment('<label>Search <input type="text" name="query" /></label>'))
        ->toBe([])
        ->and($contract->inspectFragment('<label for="query">Search</label><input id="query" type="text" />'))
        ->toBe([])
        ->and($contract->inspectFragment('<input type="text" aria-label="Search" />'))
        ->toBe([])
        ->and($contract->inspectFragment('<textarea aria-label="Your message"></textarea>'))
        ->toBe([])
        ->and($contract->inspectFragment('<textarea name="message"></textarea>'))
        ->toHaveCount(1)
        ->and($contract->inspectFragment('<input type="hidden" name="token" value="x" />'))
        ->toBe([]);
});

it('reports focus-order hazards: positive tabindex and focusable aria-hidden subtrees', function (): void {
    $contract = new PublicAccessibilityContract;

    expect($contract->inspectFragment('<div tabindex="3">Jumps the queue</div>'))
        ->toHaveCount(1)
        ->and($contract->inspectFragment('<div tabindex="3">Jumps the queue</div>')[0])
        ->toContain('only "0" and "-1"')
        ->and($contract->inspectFragment('<div tabindex="0">Fine</div>'))->toBe([])
        ->and($contract->inspectFragment('<div tabindex="-1">Fine</div>'))->toBe([]);

    $hiddenButFocusable = $contract->inspectFragment('<div aria-hidden="true"><a href="/x">Go</a></div>');

    expect($hiddenButFocusable)->toHaveCount(1)
        ->and($hiddenButFocusable[0])->toContain('focusable inside an aria-hidden="true" subtree')
        ->and($contract->inspectFragment('<div aria-hidden="true"><svg><path d="M0 0" /></svg></div>'))
        ->toBe([])
        ->and($contract->inspectFragment('<div aria-hidden="true"><a href="/x" tabindex="-1">Go</a></div>'))
        ->toBe([]);
});

it('rejects unrecognised ARIA roles and accepts the WAI-ARIA vocabulary the contract publishes', function (): void {
    $contract = new PublicAccessibilityContract;
    $violations = $contract->inspectFragment('<div role="navigaton">Typo</div>');

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('unrecognised ARIA role "navigaton"')
        ->and($contract->validAriaRoles())->toContain('navigation', 'tablist', 'columnheader', 'note', 'img');

    foreach (['note', 'tablist', 'table', 'row', 'columnheader', 'cell', 'region'] as $role) {
        expect($contract->inspectFragment(sprintf('<div role="%s" aria-label="Named">x</div>', $role)))->toBe([]);
    }
});

it('requires ARIA id references to be non-empty in a fragment and to resolve in a document', function (): void {
    $contract = new PublicAccessibilityContract;
    $emptyReference = $contract->inspectFragment('<div role="region" aria-labelledby="">Region</div>');

    expect($emptyReference)->toHaveCount(1)
        ->and($emptyReference[0])->toContain('declares an empty aria-labelledby');

    // A fragment may legitimately reference an id owned by a sibling partial,
    // so a fragment only checks that the reference is present.
    expect($contract->inspectFragment('<p aria-describedby="lives-in-another-partial">Copy</p>'))->toBe([]);

    $dangling = $contract->inspectDocument(<<<'HTML'
    <!DOCTYPE html>
    <html lang="en">
    <head><title>Home</title></head>
    <body><main><h1>Home</h1><p aria-describedby="nowhere">Copy</p></main></body>
    </html>
    HTML);

    expect($dangling)->toHaveCount(1)
        ->and($dangling[0])->toContain('references the id "nowhere"');
});

it('rejects interaction-state attributes on markup that is not operable', function (): void {
    $contract = new PublicAccessibilityContract;
    $violations = $contract->inspectFragment('<div aria-expanded="false">Inert</div>');

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('declares aria-expanded without being interactive')
        ->and($contract->inspectFragment('<button aria-expanded="false">Menu</button>'))->toBe([])
        ->and($contract->inspectFragment('<div role="button" tabindex="0" aria-expanded="false" aria-label="Menu">x</div>'))
        ->toBe([]);
});

it('reports duplicate ids because they break every id-based association', function (): void {
    $violations = new PublicAccessibilityContract()->inspectFragment('<p id="note">One</p><p id="note">Two</p>');

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('The id "note" is used 2 times');
});

it('judges heading nesting relative to the fragment so a section may start at h2', function (): void {
    $contract = new PublicAccessibilityContract;

    expect($contract->inspectFragment('<section><h2>Lead</h2><h3>Detail</h3><h3>Detail</h3><h2>Next</h2></section>'))
        ->toBe([]);

    $skipped = $contract->inspectFragment('<section><h2>Lead</h2><h4>Detail</h4></section>');

    expect($skipped)->toHaveCount(1)
        ->and($skipped[0])->toContain('skipping heading level(s)');

    $empty = $contract->inspectFragment('<section><h2></h2></section>');

    expect($empty)->toHaveCount(1)
        ->and($empty[0])->toContain('<h2> is empty');
});

it('passes a well-formed public document', function (): void {
    $document = <<<'HTML'
    <!DOCTYPE html>
    <html lang="en">
    <head><title>Capell — Home</title></head>
    <body>
        <nav aria-label="Primary"><a href="/">Home</a></nav>
        <main>
            <h1>Welcome</h1>
            <h2>Latest work</h2>
        </main>
        <nav aria-label="Footer"><a href="/about">About</a></nav>
    </body>
    </html>
    HTML;

    expect(new PublicAccessibilityContract()->inspectDocument($document))->toBe([]);
});

it('reports document-scoped language, title, landmark and heading defects', function (): void {
    $document = <<<'HTML'
    <!DOCTYPE html>
    <html>
    <head><title></title></head>
    <body>
        <nav><a href="/">Home</a></nav>
        <div><h1>One</h1><h1>Two</h1></div>
        <nav><a href="/about">About</a></nav>
    </body>
    </html>
    HTML;

    $violations = new PublicAccessibilityContract()->inspectDocument($document);

    expect($violations)->toContain(
        'The <html> element is missing a lang attribute; screen readers cannot choose a pronunciation.',
        'The document has no non-empty <title>.',
        'The document declares 0 main landmarks; exactly one is required so "skip to content" has a target.',
        'The document declares 2 <h1> elements; exactly one is required to name the page.',
    )->and(implode("\n", $violations))->toContain('needs a distinguishing accessible name');
});

it('accepts role="main" as the document main landmark', function (): void {
    $document = <<<'HTML'
    <!DOCTYPE html>
    <html lang="en">
    <head><title>Home</title></head>
    <body><div role="main"><h1>Home</h1></div></body>
    </html>
    HTML;

    expect(new PublicAccessibilityContract()->inspectDocument($document))->toBe([]);
});

it('treats an empty document as a violation rather than vacuously compliant', function (): void {
    expect(new PublicAccessibilityContract()->inspectDocument(''))->toBe(['The document is empty.']);
});

it('treats an opaque subtree as capable of supplying its own accessible name', function (): void {
    $contract = new PublicAccessibilityContract;
    $opaque = PublicAccessibilityContract::OPAQUE_CONTENT_ATTRIBUTE;

    expect($contract->inspectSkeletonFragment(sprintf('<button><span %s="1"></span></button>', $opaque)))->toBe([])
        ->and($contract->inspectSkeletonFragment(sprintf('<input type="text" name="q" %s="1" />', $opaque)))->toBe([])
        ->and($contract->inspectSkeletonFragment(sprintf('<div aria-expanded="false" %s="1">x</div>', $opaque)))->toBe([])
        ->and($contract->inspectFragment('<button><span></span></button>'))->toHaveCount(1);
});

it('rejects the analysis-only opaque marker in rendered fragments and documents', function (): void {
    $contract = new PublicAccessibilityContract;
    $opaque = PublicAccessibilityContract::OPAQUE_CONTENT_ATTRIBUTE;
    $fragmentViolations = $contract->inspectFragment(sprintf('<button %s="1"></button>', $opaque));
    $documentViolations = $contract->inspectDocument(sprintf(
        '<html lang="en"><head><title>Home</title></head><body><main><h1 %s="1"></h1></main></body></html>',
        $opaque,
    ));

    expect(implode("\n", $fragmentViolations))
        ->toContain('analysis-only data-capell-accessibility-opaque marker')
        ->toContain('requires an accessible name')
        ->and(implode("\n", $documentViolations))
        ->toContain('analysis-only data-capell-accessibility-opaque marker')
        ->toContain('<h1> is empty');
});

it('requires accessible names when any token in a multi-role fallback is interactive', function (): void {
    $contract = new PublicAccessibilityContract;

    expect($contract->inspectFragment('<div role="button checkbox"></div>'))
        ->toHaveCount(1)
        ->and($contract->inspectFragment('<div role="button checkbox"></div>')[0])
        ->toContain('requires an accessible name')
        ->and($contract->inspectFragment('<div role="button checkbox" aria-label="Select item"></div>'))
        ->toBe([]);
});

it('counts only named sections and forms as landmarks and rejects duplicate landmark names', function (): void {
    $contract = new PublicAccessibilityContract;
    $document = <<<'HTML'
    <html lang="en">
    <head><title>Home</title></head>
    <body>
        <main>
            <h1>Home</h1>
            <section><h2>Ordinary section</h2></section>
            <section><h2>Another ordinary section</h2></section>
            <form><button>Save</button></form>
            <form><button>Send</button></form>
        </main>
    </body>
    </html>
    HTML;

    expect($contract->inspectDocument($document))->toBe([]);

    $duplicateNames = str_replace(
        '</main>',
        '<nav aria-label="Related"><a href="/one">One</a></nav><nav aria-label=" related "><a href="/two">Two</a></nav></main>',
        $document,
    );

    expect(implode("\n", $contract->inspectDocument($duplicateNames)))
        ->toContain('2 <nav> landmarks named "related"');
});

it('requires authored names to contribute non-empty computed text in rendered output', function (): void {
    $contract = new PublicAccessibilityContract;
    $document = <<<'HTML'
    <html lang="en">
    <head><title>Names</title></head>
    <body>
        <main>
            <h1>Names</h1>
            <span id="empty-label"><span aria-hidden=" TRUE ">Ignored</span></span>
            <button aria-labelledby="empty-label"></button>
            <label for="query"><span hidden>Ignored</span></label>
            <input id="query" type="search">
        </main>
    </body>
    </html>
    HTML;

    $violations = implode("\n", $contract->inspectDocument($document));

    expect($violations)
        ->toContain('requires an accessible name')
        ->toContain('has no associated label')
        ->and($contract->inspectFragment('<button><span hidden>Ignored</span></button>'))
        ->toHaveCount(1)
        ->and($contract->inspectFragment('<button><img hidden alt="Ignored"></button>'))
        ->toHaveCount(1)
        ->and($contract->inspectFragment('<button><svg aria-hidden="true"><title>Ignored</title></svg></button>'))
        ->toHaveCount(1);
});

it('uses the first recognised concrete role token and rejects abstract roles', function (): void {
    $contract = new PublicAccessibilityContract;

    expect($contract->inspectFragment('<div role="future-button button" aria-label="Apply"></div>'))
        ->toBe([])
        ->and($contract->inspectFragment('<div role="presentation button"></div>'))
        ->toBe([])
        ->and(implode("\n", $contract->inspectFragment('<div role="widget"></div>')))
        ->toContain('abstract ARIA role "widget"')
        ->and($contract->inspectFragment('<div role="future-button"></div>'))
        ->toHaveCount(1);
});

it('normalizes ARIA values and ignores disabled, hidden, and inert controls in focusability checks', function (): void {
    $contract = new PublicAccessibilityContract;

    expect($contract->inspectFragment('<button aria-expanded=" TRUE ">Menu</button>'))
        ->toBe([])
        ->and(implode("\n", $contract->inspectFragment('<button aria-expanded="sometimes">Menu</button>')))
        ->toContain('invalid aria-expanded="sometimes"')
        ->and($contract->inspectFragment('<div aria-hidden=" TRUE "><button disabled>Hidden</button></div>'))
        ->toBe([])
        ->and($contract->inspectFragment('<div aria-hidden="true"><div inert><a href="/x">Hidden</a></div></div>'))
        ->toBe([])
        ->and($contract->inspectFragment('<div aria-hidden="true"><fieldset disabled><button>Hidden</button></fieldset></div>'))
        ->toBe([]);
});

it('resolves ARIA id references containing an apostrophe without breaking the XPath lookup', function (): void {
    $document = <<<'HTML'
    <!DOCTYPE html>
    <html lang="en">
    <head><title>Home</title></head>
    <body><main><h1>Home</h1><p aria-describedby="it's-here">Copy</p><span id="it's-here">Note</span></main></body>
    </html>
    HTML;

    expect(new PublicAccessibilityContract()->inspectDocument($document))->toBe([]);
});
