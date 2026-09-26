<?php

declare(strict_types=1);

namespace AIForge\Campaign;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Read-only view of serialized Gutenberg HTML: a template or a generation.
 *
 * Parsed with DOMDocument rather than parse_blocks() so the checks run without
 * WordPress. Slots are recognised by the classes their blocks render.
 */
final class GenerationDocument
{
    private const INLINE = ['a', 'abbr', 'b', 'code', 'em', 'i', 'mark', 's', 'small', 'span', 'strong', 'sub', 'sup', 'u'];
    private const HEADINGS = './/h1|.//h2|.//h3|.//h4|.//h5|.//h6';
    private const QUOTE_MARKS = '/^[\s"\'«»“”„‹›]+|[\s"\'«»“”„‹›]+$/u';

    private DOMXPath $xpath;
    private DOMNode $root;

    public function __construct(string $html)
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="aiforge-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->xpath = new DOMXPath($document);
        $this->root = $this->xpath->query('//div[@id="aiforge-root"]')->item(0) ?? $document;
    }

    public function hasStatSlot(): bool
    {
        return $this->withClass('is-style-stat-value') !== [];
    }

    public function hasTestimonialSlot(): bool
    {
        return $this->withClass('is-style-testimonial') !== [];
    }

    public function hasButton(): bool
    {
        return $this->withClass('wp-block-button__link') !== [];
    }

    public function hasCardGrid(int $minCards = 3): bool
    {
        foreach ($this->cardGrids() as $cards) {
            if (\count($cards) >= $minCards) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    public function statValues(): array
    {
        return array_map(self::textOf(...), $this->withClass('is-style-stat-value'));
    }

    /**
     * @return array<int, array{quote: string, attribution: ?string}>
     */
    public function testimonials(): array
    {
        $testimonials = [];

        foreach ($this->withClass('is-style-testimonial') as $element) {
            $cite = $this->xpath->query('.//cite', $element)->item(0);
            $quote = self::textOf($element);
            $attribution = null;

            if ($cite !== null) {
                $attribution = self::textOf($cite);
                $quote = trim(str_replace($attribution, '', $quote));
            } else {
                $attribution = $this->attributionAfter($element);
            }

            $testimonials[] = [
                'quote' => (string) preg_replace(self::QUOTE_MARKS, '', $quote),
                'attribution' => $attribution === '' ? null : $attribution,
            ];
        }

        return $testimonials;
    }

    /**
     * Card headings of every equal-width columns block that holds at least two
     * of them. A stat row is not a card grid, whatever its columns hold.
     *
     * @return array<int, string[]>
     */
    public function cardGrids(): array
    {
        $grids = [];

        foreach ($this->withClass('wp-block-columns') as $columns) {
            $cols = [];

            foreach ($columns->childNodes as $child) {
                if ($child instanceof DOMElement && self::hasClass($child, 'wp-block-column')) {
                    $cols[] = $child;
                }
            }

            if (\count($cols) < 2) {
                continue;
            }

            foreach ($cols as $col) {
                if (str_contains($col->getAttribute('style'), 'flex-basis')) {
                    continue 2;
                }
            }

            if ($this->xpath->query('.//*[' . self::classPredicate('is-style-stat-value') . ']', $columns)->length > 0) {
                continue;
            }

            $cards = [];

            foreach ($cols as $col) {
                $heading = $this->xpath->query(self::HEADINGS, $col)->item(0);

                if ($heading !== null) {
                    $cards[] = self::textOf($heading);
                }
            }

            if (\count($cards) >= 2) {
                $grids[] = $cards;
            }
        }

        return $grids;
    }

    /**
     * @return string[]
     */
    public function buttons(): array
    {
        return array_map(self::textOf(...), $this->withClass('wp-block-button__link'));
    }

    /**
     * @return string[]
     */
    public function headings(): array
    {
        $headings = [];

        foreach ($this->xpath->query(self::HEADINGS, $this->root) as $heading) {
            $headings[] = self::textOf($heading);
        }

        return $headings;
    }

    /**
     * @return string[]
     */
    public function links(): array
    {
        $links = [];

        foreach ($this->xpath->query('.//a[@href]', $this->root) as $anchor) {
            if ($anchor instanceof DOMElement) {
                $links[] = trim($anchor->getAttribute('href'));
            }
        }

        return $links;
    }

    public function plainText(): string
    {
        return self::textOf($this->root);
    }

    public static function textOf(DOMNode $node): string
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}", "\u{2007}"], ' ', self::collect($node));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function collect(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
            return (string) $node->nodeValue;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE && $node->nodeType !== XML_DOCUMENT_NODE) {
            return '';
        }

        if (!$node->hasChildNodes()) {
            return $node->nodeName === 'br' ? ' ' : '';
        }

        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= self::collect($child);
        }

        return \in_array($node->nodeName, self::INLINE, true) ? $text : ' ' . $text . ' ';
    }

    private function attributionAfter(DOMElement $testimonial): ?string
    {
        for ($node = $testimonial->nextSibling; $node !== null; $node = $node->nextSibling) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if (self::hasClass($node, 'is-style-testimonial')) {
                return null;
            }

            $strongs = $node->nodeName === 'strong' ? [$node] : $this->xpath->query('.//strong', $node);

            foreach ($strongs as $strong) {
                if ($strong instanceof DOMElement && self::leadsItsBlock($strong)) {
                    return self::textOf($strong);
                }
            }
        }

        return null;
    }

    /**
     * A name line starts with its bold name ("Marie Durand", "— Paul, client");
     * a bold phrase inside running prose is emphasis, not an attribution.
     */
    private static function leadsItsBlock(DOMElement $strong): bool
    {
        $parent = $strong->parentNode;

        if (!$parent instanceof DOMElement) {
            return true;
        }

        $before = strstr(self::textOf($parent), self::textOf($strong), true);

        return $before === false || preg_match('/[\p{L}\d]/u', $before) !== 1;
    }

    /**
     * @return DOMElement[]
     */
    private function withClass(string $class): array
    {
        $elements = [];

        foreach ($this->xpath->query('.//*[' . self::classPredicate($class) . ']', $this->root) as $element) {
            if ($element instanceof DOMElement) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    private static function classPredicate(string $class): string
    {
        return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
    }

    private static function hasClass(DOMElement $element, string $class): bool
    {
        return \in_array($class, preg_split('/\s+/', $element->getAttribute('class')) ?: [], true);
    }
}
