<?php

namespace ErnestDefoe\Recipes\Formatter;

use ErnestDefoe\Recipes\Recipe;

/**
 * Finds the recipe blocks in a post's parsed XML and reads their text.
 *
 * Shared by the renderer and the JSON-LD, so the card a reader sees and the
 * data a search engine reads come from one reading of one text.
 */
final class Blocks
{
    /** Cheap enough to run on every post: most posts end here. */
    public static function has(string $xml): bool
    {
        return str_contains($xml, '<'.Configure::TAG.'>') || str_contains($xml, '<'.Configure::TAG.' ');
    }

    public static function load(string $xml): ?\DOMDocument
    {
        $dom = new \DOMDocument();

        // s9e's XML is well-formed by construction; anything else is not ours to fix.
        return @$dom->loadXML($xml, LIBXML_NONET) ? $dom : null;
    }

    /** @return list<\DOMElement> */
    public static function elements(\DOMDocument $dom): array
    {
        $found = [];
        foreach ($dom->getElementsByTagName(Configure::TAG) as $element) {
            $found[] = $element;
        }

        return $found;
    }

    /**
     * The text between the block's start and end markers.
     *
     * 🚨 Scribe's copy is HTML, so `&` arrives as `&amp;` and has to be decoded
     * once; the BBCode's copy is the author's own text and must NOT be — a
     * recipe that says "&lt;" means those four characters.
     */
    public static function body(\DOMElement $element): string
    {
        $text = '';
        $html = false;

        foreach ($element->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->nodeName === 's') {
                $html = str_starts_with(ltrim($node->textContent), '<');
                continue;
            }

            if ($node instanceof \DOMElement && $node->nodeName === 'e') {
                continue;
            }

            $text .= $node->textContent;
        }

        if ($html) {
            // The editor writes plain text, but `ignoreTags` leaves any element
            // pasted inside as raw markup: a <br> is a line, anything else goes.
            $text = strip_tags((string) preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $text));
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $text;
    }

    /**
     * @param list<string> $fieldKeys
     * @return list<Recipe>
     */
    public static function recipes(string $xml, array $fieldKeys = []): array
    {
        if (! self::has($xml) || ! ($dom = self::load($xml))) {
            return [];
        }

        $recipes = [];
        foreach (self::elements($dom) as $element) {
            $recipe = Recipe::parse(self::body($element), $fieldKeys);
            if (! $recipe->isEmpty()) {
                $recipes[] = $recipe;
            }
        }

        return $recipes;
    }
}
