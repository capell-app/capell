<?php

declare(strict_types=1);

namespace Capell\Core\Support\Accessibility;

use Capell\Core\Support\Security\PublicOutputLeakPolicy;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;

/**
 * Canonical, browser-free accessibility contract for public Capell output.
 *
 * This is the accessibility sibling of {@see PublicOutputLeakPolicy}:
 * one shared, dependency-free policy object in Core that every theme and
 * widget package's Pest suite can assert against, instead of 30+ themes each
 * hand-rolling their own idiosyncratic `expect($blade)->toContain('aria-label=')`
 * greps.
 *
 * Every invariant below is decidable from a static HTML tree alone — no
 * layout, no computed styles, no event loop, no real browser. That boundary
 * is deliberate and is what makes the contract runnable in Pest:
 *
 * - Colour contrast, visible focus rings, reading order after CSS `order`,
 *   whether a control is actually reachable by Tab, and whether a live region
 *   announces are all *rendered/computed* properties. They are out of scope
 *   and must stay out of scope.
 * - Alt-text presence, accessible-name presence, label/control association,
 *   heading nesting, landmark uniqueness, ARIA attribute/role well-formedness
 *   and IDREF resolution are all *markup* properties. They are in scope.
 *
 * Two entry points, because themes have two shapes of output:
 *
 * - {@see inspectFragment()} for a component/section-sized chunk of markup.
 *   Document-scoped invariants (one `<main>`, one `<h1>`, `<html lang>`) are
 *   skipped, and IDREF resolution is relaxed to "attribute must be non-empty",
 *   because the referenced element may legitimately live in a sibling
 *   partial outside the fragment being inspected.
 * - {@see inspectDocument()} for a whole rendered public page, where those
 *   document-scoped invariants are meaningful and IDREFs must actually resolve.
 *
 * Callers that analyse Blade *sources* rather than rendered HTML (see the
 * theme fleet's `AssertsPublicThemeAccessibility` trait) use
 * {@see inspectSkeletonFragment()}. That entry point permits callers to mark
 * a subtree whose real content is unknowable with
 * {@see OPAQUE_CONTENT_ATTRIBUTE}. The rendered fragment/document entry
 * points reject that analysis-only marker and never let it suppress a real
 * output defect.
 */
final class PublicAccessibilityContract
{
    /**
     * Analysis-time marker for a subtree whose rendered content cannot be
     * known statically. Never emit this into real public HTML.
     */
    public const string OPAQUE_CONTENT_ATTRIBUTE = 'data-capell-accessibility-opaque';

    /**
     * Wrapper id used to isolate a fragment inside a synthetic document so
     * that fragment inspection never sees the implicit `<html>`/`<body>`
     * DOMDocument adds.
     */
    private const string FRAGMENT_ROOT_ID = 'capell-accessibility-fragment-root';

    /**
     * Elements that are focusable and operable without any author-supplied
     * `tabindex`, and therefore always require an accessible name.
     *
     * @var list<string>
     */
    private const array NATIVELY_INTERACTIVE_ELEMENTS = [
        'a', 'area', 'button', 'select', 'summary', 'textarea',
    ];

    /**
     * `role` values whose semantics promise the element behaves like a
     * control, and so require an accessible name even on a `<div>`/`<span>`.
     *
     * @var list<string>
     */
    private const array INTERACTIVE_ROLES = [
        'button', 'checkbox', 'combobox', 'link', 'menuitem', 'menuitemcheckbox',
        'menuitemradio', 'option', 'radio', 'searchbox', 'slider', 'spinbutton',
        'switch', 'tab', 'textbox', 'treeitem',
    ];

    /**
     * Roles that are not controls but still carry no intrinsic text, so an
     * author-supplied name is the only way they can be announced.
     *
     * @var list<string>
     */
    private const array NAME_REQUIRED_STRUCTURAL_ROLES = [
        'img', 'dialog', 'alertdialog', 'region',
    ];

    /**
     * `<input>` types that are buttons rather than data-entry controls: they
     * take their name from `value`/`alt`, not from an associated `<label>`.
     *
     * @var list<string>
     */
    private const array BUTTON_INPUT_TYPES = ['button', 'image', 'reset', 'submit'];

    /**
     * `<input>` types that render no control at all, and therefore need
     * neither a label nor a name.
     *
     * @var list<string>
     */
    private const array UNLABELLABLE_INPUT_TYPES = ['hidden'];

    /**
     * ARIA state/property attributes that only make sense on something the
     * user can operate. Finding them on inert markup with no interactive
     * element and no role is an authoring mistake that screen readers will
     * either ignore or announce misleadingly.
     *
     * @var list<string>
     */
    private const array INTERACTION_STATE_ATTRIBUTES = [
        'aria-checked', 'aria-expanded', 'aria-pressed', 'aria-selected',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array ARIA_ENUMERATED_VALUES = [
        'aria-checked' => ['false', 'mixed', 'true', 'undefined'],
        'aria-expanded' => ['false', 'true', 'undefined'],
        'aria-hidden' => ['false', 'true', 'undefined'],
        'aria-pressed' => ['false', 'mixed', 'true', 'undefined'],
        'aria-selected' => ['false', 'true', 'undefined'],
    ];

    /**
     * Attributes whose value is a space-separated list of element ids.
     *
     * @var list<string>
     */
    private const array IDREF_LIST_ATTRIBUTES = [
        'aria-controls', 'aria-describedby', 'aria-labelledby', 'aria-owns',
    ];

    /**
     * Landmark elements that may appear more than once per document only when
     * each instance carries a distinguishing accessible name.
     *
     * @var list<string>
     */
    private const array REPEATABLE_LANDMARK_ELEMENTS = ['aside', 'form', 'nav', 'section'];

    /**
     * WAI-ARIA 1.2 role tokens. An unrecognised `role` is silently dropped by
     * assistive technology, so a typo ("botton", "navigaton") degrades the
     * element to a bare `<div>` with no warning anywhere — exactly the class
     * of defect a contract test should catch.
     *
     * @var list<string>
     */
    private const array VALID_ARIA_ROLES = [
        'alert', 'alertdialog', 'application', 'article', 'associationlist',
        'associationlistitemkey', 'associationlistitemvalue', 'banner', 'blockquote',
        'button', 'caption', 'cell', 'checkbox', 'code', 'columnheader', 'combobox',
        'command', 'comment', 'complementary', 'composite', 'contentinfo', 'definition',
        'deletion', 'dialog', 'directory', 'document', 'emphasis', 'feed', 'figure',
        'form', 'generic', 'grid', 'gridcell', 'group', 'heading', 'image', 'img',
        'input', 'insertion', 'landmark', 'link', 'list', 'listbox', 'listitem',
        'log', 'main', 'mark', 'marquee', 'math', 'menu', 'menubar', 'menuitem',
        'menuitemcheckbox', 'menuitemradio', 'meter', 'navigation', 'none', 'note',
        'option', 'paragraph', 'presentation', 'progressbar', 'radio', 'radiogroup',
        'range', 'region', 'roletype', 'row', 'rowgroup', 'rowheader', 'scrollbar',
        'search', 'searchbox', 'section', 'sectionhead', 'select', 'separator',
        'slider', 'spinbutton', 'status', 'strong', 'structure', 'subscript',
        'suggestion', 'superscript', 'switch', 'tab', 'table', 'tablist', 'tabpanel',
        'term', 'textbox', 'time', 'timer', 'toolbar', 'tooltip', 'tree', 'treegrid',
        'treeitem', 'widget', 'window',
    ];

    /**
     * Abstract WAI-ARIA roles describe the taxonomy itself and must never be
     * authored into HTML.
     *
     * @var list<string>
     */
    private const array ABSTRACT_ARIA_ROLES = [
        'command', 'composite', 'input', 'landmark', 'range', 'roletype',
        'section', 'sectionhead', 'select', 'structure', 'widget', 'window',
    ];

    /**
     * Inspects a component/section-sized chunk of markup.
     *
     * @return list<string> Human-readable violations, empty when the fragment
     *                      satisfies the contract.
     */
    public function inspectFragment(string $html): array
    {
        return $this->inspectFragmentMarkup($html, allowOpaqueContent: false);
    }

    /**
     * Inspects a static-analysis skeleton whose component placeholders may
     * carry the analysis-only opaque-content marker.
     *
     * @return list<string> Human-readable violations, empty when the skeleton
     *                      satisfies the decidable fragment contract.
     */
    public function inspectSkeletonFragment(string $html): array
    {
        return $this->inspectFragmentMarkup($html, allowOpaqueContent: true);
    }

    /**
     * Inspects a whole rendered public page, adding the document-scoped
     * landmark/heading/language invariants that a fragment cannot decide.
     *
     * @return list<string> Human-readable violations, empty when the document
     *                      satisfies the contract.
     */
    public function inspectDocument(string $html): array
    {
        if (trim($html) === '') {
            return ['The document is empty.'];
        }

        $document = $this->parse($html, wrapAsFragment: false);
        $root = $document->documentElement;

        if (! $root instanceof DOMElement) {
            return ['The markup could not be parsed as HTML.'];
        }

        $xpath = new DOMXPath($document);

        return [
            ...$this->opaqueMarkerViolations($xpath, $root, allowOpaqueContent: false),
            ...$this->imageAlternativeTextViolations($xpath, $root),
            ...$this->accessibleNameViolations($xpath, $root, resolveIdReferences: true, allowOpaqueContent: false),
            ...$this->labelAssociationViolations($xpath, $root, resolveIdReferences: true, allowOpaqueContent: false),
            ...$this->focusOrderViolations($xpath, $root),
            ...$this->ariaWellFormednessViolations($xpath, $root, resolveIdReferences: true, allowOpaqueContent: false),
            ...$this->duplicateIdViolations($xpath, $root),
            ...$this->headingNestingViolations($xpath, $root, allowOpaqueContent: false),
            ...$this->documentStructureViolations($xpath, $root, allowOpaqueContent: false),
        ];
    }

    /**
     * The role vocabulary this contract accepts, exposed so packages can
     * assert against one list rather than duplicating it.
     *
     * @return list<string>
     */
    public function validAriaRoles(): array
    {
        return array_values(array_diff(self::VALID_ARIA_ROLES, self::ABSTRACT_ARIA_ROLES));
    }

    /**
     * @return list<string>
     */
    private function inspectFragmentMarkup(string $html, bool $allowOpaqueContent): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = $this->parse($html, wrapAsFragment: true);
        $root = $document->getElementById(self::FRAGMENT_ROOT_ID);

        if (! $root instanceof DOMElement) {
            return ['The markup could not be parsed as HTML.'];
        }

        $xpath = new DOMXPath($document);

        return [
            ...$this->opaqueMarkerViolations($xpath, $root, $allowOpaqueContent),
            ...$this->imageAlternativeTextViolations($xpath, $root),
            ...$this->accessibleNameViolations(
                $xpath,
                $root,
                resolveIdReferences: false,
                allowOpaqueContent: $allowOpaqueContent,
            ),
            ...$this->labelAssociationViolations(
                $xpath,
                $root,
                resolveIdReferences: false,
                allowOpaqueContent: $allowOpaqueContent,
            ),
            ...$this->focusOrderViolations($xpath, $root),
            ...$this->ariaWellFormednessViolations(
                $xpath,
                $root,
                resolveIdReferences: false,
                allowOpaqueContent: $allowOpaqueContent,
            ),
            ...$this->duplicateIdViolations($xpath, $root),
            ...$this->headingNestingViolations($xpath, $root, $allowOpaqueContent),
        ];
    }

    /**
     * The opaque marker is an analysis-only sentinel. Seeing it in rendered
     * output is both a public implementation leak and an attempt to bypass
     * checks that must remain strict for real HTML.
     *
     * @return list<string>
     */
    private function opaqueMarkerViolations(
        DOMXPath $xpath,
        DOMElement $root,
        bool $allowOpaqueContent,
    ): array {
        if ($allowOpaqueContent) {
            return [];
        }

        $markedElements = $this->query(
            $xpath,
            $root,
            sprintf('.//*[@%s]', self::OPAQUE_CONTENT_ATTRIBUTE),
        );

        if ($root->hasAttribute(self::OPAQUE_CONTENT_ATTRIBUTE)) {
            array_unshift($markedElements, $root);
        }

        return array_map(
            fn (DOMElement $element): string => sprintf(
                '%s exposes the analysis-only %s marker in rendered public output.',
                $this->describe($element),
                self::OPAQUE_CONTENT_ATTRIBUTE,
            ),
            $markedElements,
        );
    }

    /**
     * Every `<img>` must declare `alt`. An absent attribute makes assistive
     * technology fall back to announcing the file name; an explicitly empty
     * `alt=""` is the correct, checkable way to declare an image decorative,
     * so it passes.
     *
     * @return list<string>
     */
    private function imageAlternativeTextViolations(DOMXPath $xpath, DOMElement $root): array
    {
        $violations = [];

        foreach ($this->query($xpath, $root, './/img') as $image) {
            if ($image->hasAttribute('alt')) {
                continue;
            }

            $violations[] = sprintf(
                '<img> is missing an alt attribute (%s); use alt="" to declare it decorative.',
                $this->describe($image),
            );
        }

        return $violations;
    }

    /**
     * Interactive controls, and structural roles with no intrinsic text, must
     * expose a non-empty accessible name. The name may come from
     * `aria-label`, `aria-labelledby`, `title`, descendant text, a descendant
     * `<img alt>`, an `<svg><title>`, an `<input value>`, or an opaque subtree.
     *
     * @return list<string>
     */
    private function accessibleNameViolations(
        DOMXPath $xpath,
        DOMElement $root,
        bool $resolveIdReferences,
        bool $allowOpaqueContent,
    ): array {
        $violations = [];

        foreach ($this->query($xpath, $root, './/*') as $element) {
            if (! $this->requiresAccessibleName($element)) {
                continue;
            }

            if ($this->accessibleName(
                $xpath,
                $root,
                $element,
                $resolveIdReferences,
                $allowOpaqueContent,
            ) !== '') {
                continue;
            }

            $violations[] = sprintf(
                '%s requires an accessible name but exposes none; add text content, aria-label, or aria-labelledby.',
                $this->describe($element),
            );
        }

        return $violations;
    }

    /**
     * Data-entry controls must be programmatically associated with a label:
     * a wrapping `<label>`, a `<label for>` pointing at the control's id, or
     * `aria-label`/`aria-labelledby`/`title`. A bare `placeholder` is not an
     * accessible label and deliberately does not satisfy this check.
     *
     * @return list<string>
     */
    private function labelAssociationViolations(
        DOMXPath $xpath,
        DOMElement $root,
        bool $resolveIdReferences,
        bool $allowOpaqueContent,
    ): array {
        $labelledIds = $this->labelTargetIds($xpath, $root, $allowOpaqueContent);
        $violations = [];

        foreach ($this->query($xpath, $root, './/input | .//select | .//textarea') as $control) {
            if (! $this->isLabellableControl($control)) {
                continue;
            }

            if (in_array(true, [
                $this->hasOpaqueContent($control, $allowOpaqueContent),
                $this->authoredAccessibleName($xpath, $root, $control, $resolveIdReferences) !== '',
            ], true)) {
                continue;
            }

            if ($this->hasAncestorLabel($control, $allowOpaqueContent)) {
                continue;
            }

            $controlId = trim($control->getAttribute('id'));

            if ($controlId !== '' && in_array($controlId, $labelledIds, true)) {
                continue;
            }

            $violations[] = sprintf(
                '%s has no associated label; wrap it in <label>, point a <label for> at its id, or set aria-label.',
                $this->describe($control),
            );
        }

        return $violations;
    }

    /**
     * Focus-order affordances that are decidable from markup:
     *
     * - A positive `tabindex` pulls the element ahead of the document order
     *   for every keyboard user, so only `0` and `-1` are permitted.
     * - `aria-hidden="true"` removes a subtree from the accessibility tree
     *   while leaving it in the tab order, producing a focusable control that
     *   screen readers cannot announce.
     *
     * @return list<string>
     */
    private function focusOrderViolations(DOMXPath $xpath, DOMElement $root): array
    {
        $violations = [];

        foreach ($this->query($xpath, $root, './/*[@tabindex]') as $element) {
            $tabIndex = trim($element->getAttribute('tabindex'));
            if (in_array($tabIndex, ['0', '-1'], true)) {
                continue;
            }

            $violations[] = sprintf(
                '%s declares tabindex="%s"; only "0" and "-1" keep the document tab order predictable.',
                $this->describe($element),
                $tabIndex,
            );
        }

        foreach ($this->query($xpath, $root, './/*[@aria-hidden]') as $hidden) {
            if ($this->normalizedAttributeValue($hidden, 'aria-hidden') !== 'true') {
                continue;
            }

            if ($this->isFocusable($hidden)) {
                $violations[] = sprintf(
                    '%s is aria-hidden="true" but still focusable; screen readers cannot announce it.',
                    $this->describe($hidden),
                );
            }

            foreach ($this->query($xpath, $hidden, './/*') as $descendant) {
                if (! $this->isFocusable($descendant)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s is focusable inside an aria-hidden="true" subtree; screen readers cannot announce it.',
                    $this->describe($descendant),
                );
            }
        }

        return $violations;
    }

    /**
     * ARIA correctness where the markup already implies it: recognised role
     * tokens, non-empty IDREF lists (resolved to real elements in document
     * mode), and interaction-state attributes only on operable elements.
     *
     * @return list<string>
     */
    private function ariaWellFormednessViolations(
        DOMXPath $xpath,
        DOMElement $root,
        bool $resolveIdReferences,
        bool $allowOpaqueContent,
    ): array {
        $violations = [];

        foreach ($this->query($xpath, $root, './/*[@role]') as $element) {
            $roles = $this->roleTokens($element);
            $winningRole = $this->resolvedRole($element);

            foreach ($roles as $role) {
                if (in_array($role, self::ABSTRACT_ARIA_ROLES, true)) {
                    $violations[] = sprintf(
                        '%s declares the abstract ARIA role "%s"; abstract roles cannot be used in markup.',
                        $this->describe($element),
                        $role,
                    );
                }
            }

            if ($winningRole === null) {
                foreach ($roles as $role) {
                    if (in_array($role, self::VALID_ARIA_ROLES, true)) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s declares the unrecognised ARIA role "%s"; assistive technology drops unknown roles silently.',
                        $this->describe($element),
                        $role,
                    );
                }
            }
        }

        foreach (self::IDREF_LIST_ATTRIBUTES as $attribute) {
            foreach ($this->query($xpath, $root, sprintf('.//*[@%s]', $attribute)) as $element) {
                $referencedIds = preg_split('/\s+/', trim($element->getAttribute($attribute)), flags: PREG_SPLIT_NO_EMPTY) ?: [];

                if ($referencedIds === []) {
                    $violations[] = sprintf(
                        '%s declares an empty %s; remove the attribute or point it at an element id.',
                        $this->describe($element),
                        $attribute,
                    );

                    continue;
                }

                if (! $resolveIdReferences) {
                    continue;
                }

                foreach ($referencedIds as $referencedId) {
                    if ($this->query($xpath, $root, sprintf('.//*[@id=%s]', $this->quote($referencedId))) !== []) {
                        continue;
                    }

                    $violations[] = sprintf(
                        '%s references the id "%s" via %s, but no element with that id exists.',
                        $this->describe($element),
                        $referencedId,
                        $attribute,
                    );
                }
            }
        }

        foreach (self::INTERACTION_STATE_ATTRIBUTES as $attribute) {
            foreach ($this->query($xpath, $root, sprintf('.//*[@%s]', $attribute)) as $element) {
                if (! in_array(true, [
                    $this->isNativelyInteractive($element),
                    in_array($this->resolvedRole($element), self::INTERACTIVE_ROLES, true),
                    $this->hasOpaqueContent($element, $allowOpaqueContent),
                ], true)) {
                    $violations[] = sprintf(
                        '%s declares %s without being interactive; add the matching role or move the state onto the control.',
                        $this->describe($element),
                        $attribute,
                    );
                }
            }
        }

        foreach (self::ARIA_ENUMERATED_VALUES as $attribute => $allowedValues) {
            foreach ($this->query($xpath, $root, sprintf('.//*[@%s]', $attribute)) as $element) {
                $value = $this->normalizedAttributeValue($element, $attribute);

                if (in_array($value, $allowedValues, true)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s declares invalid %s="%s"; expected one of: %s.',
                    $this->describe($element),
                    $attribute,
                    trim($element->getAttribute($attribute)),
                    implode(', ', $allowedValues),
                );
            }
        }

        return $violations;
    }

    /**
     * Duplicate ids break every IDREF-based association (`<label for>`,
     * `aria-labelledby`, `aria-controls`) because only the first match wins.
     *
     * @return list<string>
     */
    private function duplicateIdViolations(DOMXPath $xpath, DOMElement $root): array
    {
        $occurrences = [];

        foreach ($this->query($xpath, $root, './/*[@id]') as $element) {
            $elementId = trim($element->getAttribute('id'));

            if ($elementId === '') {
                continue;
            }

            $occurrences[$elementId] = ($occurrences[$elementId] ?? 0) + 1;
        }

        $violations = [];

        foreach ($occurrences as $elementId => $count) {
            if ($count < 2) {
                continue;
            }

            $violations[] = sprintf(
                'The id "%s" is used %d times; duplicate ids break <label for> and aria-* references.',
                $elementId,
                $count,
            );
        }

        return $violations;
    }

    /**
     * Heading levels must descend one step at a time. The check is relative
     * to the first heading encountered, so a section fragment that
     * legitimately starts at `<h2>` is judged on its own nesting rather than
     * being told it is missing an `<h1>` it does not own.
     *
     * @return list<string>
     */
    private function headingNestingViolations(DOMXPath $xpath, DOMElement $root, bool $allowOpaqueContent): array
    {
        $violations = [];
        $previousLevel = null;

        foreach ($this->query($xpath, $root, './/h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6') as $heading) {
            $level = (int) substr($heading->nodeName, 1);

            if ($previousLevel !== null && $level > $previousLevel + 1) {
                $violations[] = sprintf(
                    '<%s> follows <h%d>, skipping heading level(s); heading levels must not jump downwards by more than one.',
                    $heading->nodeName,
                    $previousLevel,
                );
            }

            if ($this->textContentOf($heading) === '' && ! $this->hasOpaqueContent($heading, $allowOpaqueContent)) {
                $violations[] = sprintf('<%s> is empty; an empty heading announces nothing.', $heading->nodeName);
            }

            $previousLevel = $level;
        }

        return $violations;
    }

    /**
     * Document-scoped landmark, heading and language invariants — meaningful
     * only for a whole page, which is why they live outside
     * {@see inspectFragment()}.
     *
     * @return list<string>
     */
    private function documentStructureViolations(DOMXPath $xpath, DOMElement $root, bool $allowOpaqueContent): array
    {
        $violations = [];

        if (trim($root->getAttribute('lang')) === '') {
            $violations[] = 'The <html> element is missing a lang attribute; screen readers cannot choose a pronunciation.';
        }

        $titles = $this->query($xpath, $root, './/head/title');

        if ($titles === [] || $this->textContentOf($titles[0]) === '') {
            $violations[] = 'The document has no non-empty <title>.';
        }

        $mainLandmarks = array_values(array_filter(
            $this->query($xpath, $root, './/main | .//*[@role]'),
            fn (DOMElement $element): bool => $element->nodeName === 'main'
                || $this->resolvedRole($element) === 'main',
        ));

        if (count($mainLandmarks) !== 1) {
            $violations[] = sprintf(
                'The document declares %d main landmarks; exactly one is required so "skip to content" has a target.',
                count($mainLandmarks),
            );
        }

        $topLevelHeadings = $this->query($xpath, $root, './/h1');

        if (count($topLevelHeadings) !== 1) {
            $violations[] = sprintf(
                'The document declares %d <h1> elements; exactly one is required to name the page.',
                count($topLevelHeadings),
            );
        }

        foreach (self::REPEATABLE_LANDMARK_ELEMENTS as $landmarkElement) {
            $landmarks = array_values(array_filter(
                $this->query($xpath, $root, sprintf('.//%s', $landmarkElement)),
                function (DOMElement $element) use ($xpath, $root, $landmarkElement, $allowOpaqueContent): bool {
                    if (! in_array($landmarkElement, ['form', 'section'], true)) {
                        return true;
                    }

                    return $this->landmarkName(
                        $xpath,
                        $root,
                        $element,
                        allowOpaqueContent: $allowOpaqueContent,
                    ) !== '';
                },
            ));

            if (count($landmarks) < 2) {
                continue;
            }

            $names = [];

            foreach ($landmarks as $landmark) {
                $name = $this->landmarkName(
                    $xpath,
                    $root,
                    $landmark,
                    allowOpaqueContent: $allowOpaqueContent,
                );

                if ($name === '') {
                    $violations[] = sprintf(
                        'The document has %d <%s> landmarks, so each needs a distinguishing accessible name; %s has none.',
                        count($landmarks),
                        $landmarkElement,
                        $this->describe($landmark),
                    );

                    continue;
                }

                $normalizedName = mb_strtolower($name);
                $names[$normalizedName] = ($names[$normalizedName] ?? 0) + 1;
            }

            foreach ($names as $name => $count) {
                if ($count < 2) {
                    continue;
                }

                $violations[] = sprintf(
                    'The document has %d <%s> landmarks named "%s"; repeated landmarks need distinct accessible names.',
                    $count,
                    $landmarkElement,
                    $name,
                );
            }
        }

        return $violations;
    }

    private function requiresAccessibleName(DOMElement $element): bool
    {
        $role = $this->resolvedRole($element);

        if (in_array($role, self::INTERACTIVE_ROLES, true)
            || in_array($role, self::NAME_REQUIRED_STRUCTURAL_ROLES, true)) {
            return true;
        }

        if ($role === 'presentation' || $role === 'none') {
            return false;
        }

        if ($this->normalizedAttributeValue($element, 'aria-hidden') === 'true') {
            return false;
        }

        if ($element->nodeName === 'input') {
            return in_array($this->inputType($element), self::BUTTON_INPUT_TYPES, true);
        }

        if ($element->nodeName === 'a' || $element->nodeName === 'area') {
            return $element->hasAttribute('href');
        }

        return in_array($element->nodeName, self::NATIVELY_INTERACTIVE_ELEMENTS, true);
    }

    private function accessibleName(
        DOMXPath $xpath,
        DOMElement $root,
        DOMElement $element,
        bool $resolveIdReferences,
        bool $allowOpaqueContent,
    ): string {
        $authoredName = $this->authoredAccessibleName($xpath, $root, $element, $resolveIdReferences);

        if ($authoredName !== '') {
            return $authoredName;
        }

        if ($this->hasOpaqueContent($element, $allowOpaqueContent)) {
            return 'opaque-content';
        }

        if ($element->nodeName === 'input') {
            $inputType = $this->inputType($element);

            if ($inputType === 'image') {
                return trim($element->getAttribute('alt'));
            }

            // `submit` and `reset` carry a user-agent default label.
            if ($inputType === 'submit' || $inputType === 'reset') {
                return $inputType;
            }

            return trim($element->getAttribute('value'));
        }

        // `<select>` and `<textarea>` take their name from a label, which
        // labelAssociationViolations() already reports on; do not report the
        // same control twice under two different messages.
        if ($element->nodeName === 'select' || $element->nodeName === 'textarea') {
            return 'labelled-control';
        }

        $contentName = $this->namingTextOf($element, $allowOpaqueContent);

        if ($contentName !== '') {
            return $contentName;
        }

        return '';
    }

    private function authoredAccessibleName(
        DOMXPath $xpath,
        DOMElement $root,
        DOMElement $element,
        bool $resolveIdReferences,
    ): string {
        $ariaLabel = trim($element->getAttribute('aria-label'));

        if ($ariaLabel !== '') {
            return $ariaLabel;
        }

        $labelledBy = preg_split(
            '/\s+/',
            trim($element->getAttribute('aria-labelledby')),
            flags: PREG_SPLIT_NO_EMPTY,
        ) ?: [];

        if ($labelledBy !== []) {
            $parts = [];

            foreach ($labelledBy as $referencedId) {
                $targets = $this->query($xpath, $root, sprintf('.//*[@id=%s]', $this->quote($referencedId)));

                if ($targets === []) {
                    if (! $resolveIdReferences) {
                        return implode(' ', $labelledBy);
                    }

                    continue;
                }

                $text = $this->namingTextOf(
                    $targets[0],
                    allowOpaqueContent: false,
                    allowHiddenSubtree: $this->isHiddenFromNaming($targets[0]),
                );

                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return implode(' ', $parts);
        }

        return trim($element->getAttribute('title'));
    }

    private function landmarkName(
        DOMXPath $xpath,
        DOMElement $root,
        DOMElement $element,
        bool $allowOpaqueContent,
    ): string {
        $name = $this->authoredAccessibleName($xpath, $root, $element, resolveIdReferences: true);

        if ($name !== '') {
            return $name;
        }

        return $this->hasOpaqueContent($element, $allowOpaqueContent) ? 'opaque-content' : '';
    }

    private function hasOpaqueContent(DOMElement $element, bool $allowOpaqueContent): bool
    {
        if (! $allowOpaqueContent) {
            return false;
        }

        if ($element->hasAttribute(self::OPAQUE_CONTENT_ATTRIBUTE)) {
            return true;
        }

        return array_any($this->descendantElements($element), fn (DOMElement $descendant): bool => $descendant->hasAttribute(self::OPAQUE_CONTENT_ATTRIBUTE));
    }

    private function isLabellableControl(DOMElement $control): bool
    {
        if ($this->normalizedAttributeValue($control, 'aria-hidden') === 'true') {
            return false;
        }

        if ($control->nodeName !== 'input') {
            return true;
        }

        $inputType = $this->inputType($control);

        return ! in_array($inputType, self::BUTTON_INPUT_TYPES, true)
            && ! in_array($inputType, self::UNLABELLABLE_INPUT_TYPES, true);
    }

    /**
     * @return list<string>
     */
    private function labelTargetIds(DOMXPath $xpath, DOMElement $root, bool $allowOpaqueContent): array
    {
        $targetIds = [];

        foreach ($this->query($xpath, $root, './/label[@for]') as $label) {
            $targetId = trim($label->getAttribute('for'));

            if ($targetId !== '' && $this->namingTextOf($label, $allowOpaqueContent) !== '') {
                $targetIds[] = $targetId;
            }
        }

        return array_values(array_unique($targetIds));
    }

    private function hasAncestorLabel(DOMElement $control, bool $allowOpaqueContent): bool
    {
        $ancestor = $control->parentNode;

        while ($ancestor instanceof DOMElement) {
            if ($ancestor->nodeName === 'label') {
                return $this->namingTextOf($ancestor, $allowOpaqueContent) !== '';
            }

            $ancestor = $ancestor->parentNode;
        }

        return false;
    }

    private function isFocusable(DOMElement $element): bool
    {
        if ($this->isHiddenOrInert($element) || $this->isEffectivelyDisabled($element)) {
            return false;
        }

        $tabIndex = trim($element->getAttribute('tabindex'));

        if ($tabIndex !== '') {
            return $tabIndex !== '-1';
        }

        if ($element->nodeName === 'input') {
            return ! in_array($this->inputType($element), self::UNLABELLABLE_INPUT_TYPES, true);
        }

        if ($element->nodeName === 'a' || $element->nodeName === 'area') {
            return $element->hasAttribute('href');
        }

        return in_array($element->nodeName, self::NATIVELY_INTERACTIVE_ELEMENTS, true);
    }

    private function isNativelyInteractive(DOMElement $element): bool
    {
        if ($element->nodeName === 'input') {
            return ! in_array($this->inputType($element), self::UNLABELLABLE_INPUT_TYPES, true);
        }

        if ($element->nodeName === 'a' || $element->nodeName === 'area') {
            return $element->hasAttribute('href');
        }

        return in_array($element->nodeName, self::NATIVELY_INTERACTIVE_ELEMENTS, true);
    }

    private function inputType(DOMElement $input): string
    {
        $inputType = strtolower(trim($input->getAttribute('type')));

        return $inputType === '' ? 'text' : $inputType;
    }

    private function isHiddenOrInert(DOMElement $element): bool
    {
        $current = $element;

        while ($current instanceof DOMElement) {
            if ($current->hasAttribute('hidden') || $current->hasAttribute('inert')) {
                return true;
            }

            $current = $current->parentNode;
        }

        return false;
    }

    private function isEffectivelyDisabled(DOMElement $element): bool
    {
        if ($this->supportsDisabledAttribute($element) && $element->hasAttribute('disabled')) {
            return true;
        }

        if (! $this->supportsDisabledAttribute($element)) {
            return false;
        }

        $ancestor = $element->parentNode;

        while ($ancestor instanceof DOMElement) {
            if ($ancestor->nodeName === 'fieldset'
                && $ancestor->hasAttribute('disabled')
                && ! $this->isInsideFirstLegend($element, $ancestor)) {
                return true;
            }

            $ancestor = $ancestor->parentNode;
        }

        return false;
    }

    private function supportsDisabledAttribute(DOMElement $element): bool
    {
        return in_array(
            $element->nodeName,
            ['button', 'fieldset', 'input', 'optgroup', 'option', 'select', 'textarea'],
            true,
        );
    }

    private function isInsideFirstLegend(DOMElement $element, DOMElement $fieldset): bool
    {
        $firstLegend = null;

        foreach ($fieldset->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'legend') {
                $firstLegend = $child;

                break;
            }
        }

        if (! $firstLegend instanceof DOMElement) {
            return false;
        }

        $ancestor = $element;

        while ($ancestor instanceof DOMElement && $ancestor !== $fieldset) {
            if ($ancestor === $firstLegend) {
                return true;
            }

            $ancestor = $ancestor->parentNode;
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function roleTokens(DOMElement $element): array
    {
        return array_map(
            strtolower(...),
            preg_split('/\s+/', trim($element->getAttribute('role')), flags: PREG_SPLIT_NO_EMPTY) ?: [],
        );
    }

    private function resolvedRole(DOMElement $element): ?string
    {
        foreach ($this->roleTokens($element) as $role) {
            if (in_array($role, self::VALID_ARIA_ROLES, true)
                && ! in_array($role, self::ABSTRACT_ARIA_ROLES, true)) {
                return $role;
            }
        }

        return null;
    }

    private function normalizedAttributeValue(DOMElement $element, string $attribute): string
    {
        return strtolower(trim($element->getAttribute($attribute)));
    }

    private function namingTextOf(
        DOMElement $element,
        bool $allowOpaqueContent,
        bool $allowHiddenSubtree = false,
    ): string {
        if (! $allowHiddenSubtree && $this->isHiddenFromNaming($element)) {
            return '';
        }

        if ($this->hasOpaqueContent($element, $allowOpaqueContent)) {
            return 'opaque-content';
        }

        if ($element->nodeName === 'img') {
            return trim($element->getAttribute('alt'));
        }

        $parts = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $text = $this->namingTextOf($child, $allowOpaqueContent, $allowHiddenSubtree);

                if ($text !== '') {
                    $parts[] = $text;
                }

                continue;
            }

            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $this->textContentOf($child);

                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    private function isHiddenFromNaming(DOMElement $element): bool
    {
        return in_array(true, [
            $element->hasAttribute('hidden'),
            $element->hasAttribute('inert'),
            $this->normalizedAttributeValue($element, 'aria-hidden') === 'true',
            in_array($element->nodeName, ['script', 'style', 'template'], true),
        ], true);
    }

    private function textContentOf(?DOMNode $node): string
    {
        if (! $node instanceof DOMNode) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? $node->textContent);
    }

    /**
     * Renders a short, stable identifier for an element so a failure message
     * points at recognisable markup rather than a bare tag name.
     */
    private function describe(DOMElement $element): string
    {
        $description = '<' . $element->nodeName;

        foreach (['id', 'class', 'name', 'type', 'role', 'href'] as $attribute) {
            $value = trim($element->getAttribute($attribute));

            if ($value === '') {
                continue;
            }

            if (mb_strlen($value) > 40) {
                $value = mb_substr($value, 0, 40) . '…';
            }

            $description .= sprintf(' %s="%s"', $attribute, $value);
        }

        return $description . '>';
    }

    /**
     * @return list<DOMElement>
     */
    private function query(DOMXPath $xpath, DOMElement $context, string $expression): array
    {
        $matches = $xpath->query($expression, $context);

        if (! $matches instanceof DOMNodeList) {
            return [];
        }

        $elements = [];

        foreach ($matches as $match) {
            if ($match instanceof DOMElement) {
                $elements[] = $match;
            }
        }

        return $elements;
    }

    /**
     * @return list<DOMElement>
     */
    private function descendantElements(DOMElement $element): array
    {
        $descendants = [];

        foreach ($element->getElementsByTagName('*') as $descendant) {
            if ($descendant instanceof DOMElement) {
                $descendants[] = $descendant;
            }
        }

        return $descendants;
    }

    /**
     * Quotes a value for interpolation into an XPath expression, handling the
     * apostrophe case XPath 1.0 has no escape syntax for.
     */
    private function quote(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        $segments = array_map(
            static fn (string $segment): string => "'" . $segment . "'",
            explode("'", $value),
        );

        return 'concat(' . implode(', "\'", ', $segments) . ')';
    }

    private function parse(string $html, bool $wrapAsFragment): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousInternalErrors = libxml_use_internal_errors(true);

        $markup = $wrapAsFragment
            ? '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body><div id="'
                . self::FRAGMENT_ROOT_ID . '">' . $html . '</div></body></html>'
            : '<!DOCTYPE html>' . $html;

        $document->loadHTML($markup, LIBXML_HTML_NODEFDTD | LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previousInternalErrors);

        return $document;
    }
}
