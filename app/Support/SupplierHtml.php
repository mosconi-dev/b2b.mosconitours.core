<?php

namespace App\Support;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Supplier prose, reduced to the tags we are willing to render.
 *
 * TBO writes hotel descriptions as HTML — paragraphs, bold run-in headings, bullet
 * lists — so escaping it prints "<p><strong>Hotel Overview:</strong>" onto the page,
 * and rendering it untouched hands a third party arbitrary markup inside a logged-in
 * session with a wallet attached. Neither is acceptable, so the formatting is kept
 * and everything else is dropped.
 *
 * Attributes go too, all of them: nothing in the allow-list needs one, so there is
 * nowhere for an onclick, a style or an href to hide. That is also why this parses
 * rather than pattern-matches — `<p title="a>b">` defeats a regex, and supplier
 * markup is not well-formed enough to assume otherwise.
 */
class SupplierHtml
{
    /** Tags worth keeping. Anything else is unwrapped: the words stay, the tag goes. */
    private const ALLOWED = ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li'];

    /** Tags whose contents are code or markup rather than prose, removed whole. */
    private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'noscript', 'template', 'head'];

    public static function clean(?string $html): ?string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return null;
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // Supplier markup is rarely well-formed — the descriptions nest <ul> inside
        // <p> — so parse errors are expected, and libxml's recovered tree is exactly
        // what we want to walk.
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div>'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementsByTagName('div')->item(0);

        if ($root === null) {
            return null;
        }

        self::scrub($root);

        $clean = '';

        foreach ($root->childNodes as $child) {
            $clean .= $document->saveHTML($child);
        }

        $clean = trim($clean);

        return $clean === '' ? null : $clean;
    }

    /**
     * Strip a subtree down to the allow-list, in place.
     */
    private static function scrub(DOMNode $node): void
    {
        // Snapshot first: removing children while walking a live DOMNodeList skips
        // every other node.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $name = strtolower($child->nodeName);

            if (in_array($name, self::DROPPED, true)) {
                $node->removeChild($child);

                continue;
            }

            self::scrub($child);

            if (! in_array($name, self::ALLOWED, true)) {
                self::unwrap($child);

                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $child->removeAttribute($attribute->nodeName);
            }

            // Invalid nesting leaves husks behind: the descriptions wrap lists in a
            // paragraph, and lifting the <ul> out where it belongs strands an empty
            // <p> that renders as an unexplained gap.
            if ($name !== 'br'
                && trim($child->textContent) === ''
                && $child->getElementsByTagName('br')->length === 0) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * Replace an element with its own children, keeping the text it wrapped.
     */
    private static function unwrap(DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }
}
