<?php

namespace ErnestDefoe\Recipes\Formatter;

use ErnestDefoe\Recipes\Recipe;
use Psr\Http\Message\ServerRequestInterface;
use s9e\TextFormatter\Renderer;

/**
 * Turns each recipe block into its card, just before the post is rendered.
 *
 * The block's raw text is replaced by the child tags Configure templated —
 * a description, an ingredients list, a steps list — with the reader's own
 * labels and every quantity already read (see Quantity). The XML in the
 * database is never touched, so this is a view, not a rewrite.
 *
 * Costs nothing on a post without a recipe: one `str_contains`.
 */
class Render
{
    public function __construct(private readonly Labels $labels)
    {
    }

    public function __invoke(Renderer $renderer, mixed $context, string $xml, ?ServerRequestInterface $request = null): string
    {
        if (! Blocks::has($xml) || ! ($dom = Blocks::load($xml))) {
            return $xml;
        }

        foreach (Blocks::elements($dom) as $element) {
            $recipe = Recipe::parse(Blocks::body($element));

            // Nothing recognisable: leave the source showing rather than an empty card.
            if (! $recipe->isEmpty()) {
                $this->expand($element, $recipe);
            }
        }

        $root = $dom->documentElement;

        return $root ? (string) $dom->saveXML($root) : $xml;
    }

    private function expand(\DOMElement $element, Recipe $recipe): void
    {
        $dom = $element->ownerDocument;
        assert($dom instanceof \DOMDocument);

        // Keep the s/e markers (they cost nothing to render and keep the XML
        // shaped as s9e wrote it); drop the source text.
        foreach (iterator_to_array($element->childNodes) as $node) {
            if (! ($node instanceof \DOMElement && in_array($node->nodeName, ['s', 'e'], true))) {
                $element->removeChild($node);
            }
        }
        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $element->removeAttribute($attribute->nodeName);
        }

        $end = null;
        foreach ($element->childNodes as $node) {
            if ($node->nodeName === 'e') {
                $end = $node;
            }
        }
        $add = fn (\DOMNode $node) => $element->insertBefore($node, $end);

        $element->setAttribute('v', '1');
        $this->set($element, 'title', $recipe->title);
        $this->set($element, 'image', $recipe->image);

        if ($recipe->servings !== null) {
            $element->setAttribute('servings', (string) $recipe->servings);
            $element->setAttribute('l_servings', $this->t('servings'));
        }
        foreach (['prep' => $recipe->prep, 'cook' => $recipe->cook] as $key => $seconds) {
            if ($seconds !== null) {
                $element->setAttribute($key, $this->duration($seconds));
                $element->setAttribute('l_'.$key, $this->t($key));
            }
        }
        // A total is only news when it adds two things up.
        if ($recipe->prep !== null && $recipe->cook !== null) {
            $element->setAttribute('total', $this->duration((int) $recipe->total()));
            $element->setAttribute('l_total', $this->t('total'));
        }

        if ($recipe->description !== '') {
            $add($this->node($dom, 'RECIPE_DESC', [], $recipe->description));
        }

        if ($recipe->ingredients) {
            $list = $this->node($dom, 'RECIPE_LIST', ['kind' => 'ingredients', 'label' => $this->t('ingredients')]);
            foreach ($recipe->ingredients as $item) {
                $list->appendChild($this->ingredient($dom, $item));
            }
            $add($list);
        }

        if ($recipe->steps) {
            $list = $this->node($dom, 'RECIPE_LIST', ['kind' => 'steps', 'label' => $this->t('steps')]);
            foreach ($recipe->steps as $item) {
                $list->appendChild($this->step($dom, $item));
            }
            $add($list);
        }
    }

    /** @param array<string, mixed> $item */
    private function ingredient(\DOMDocument $dom, array $item): \DOMElement
    {
        if (isset($item['heading'])) {
            return $this->node($dom, 'RECIPE_GROUP', [], (string) $item['heading']);
        }

        $q = $item['quantity'] ?? null;

        if (! is_array($q)) {
            return $this->node($dom, 'RECIPE_ING', [], (string) $item['text']);
        }

        return $this->node($dom, 'RECIPE_ING', [
            'q' => self::number($q['q']),
            'q2' => $q['q2'] === null ? null : self::number($q['q2']),
            'dec' => $q['decimal'] ? '1' : null,
            'qty' => $q['qty'],
            'gap' => $q['gap'],
            'unit' => $q['unit'],
            'one' => $q['one'],
            'many' => $q['many'],
        ], $q['rest']);
    }

    /** @param array<string, mixed> $item */
    private function step(\DOMDocument $dom, array $item): \DOMElement
    {
        if (isset($item['heading'])) {
            return $this->node($dom, 'RECIPE_GROUP', [], (string) $item['heading']);
        }

        $step = $this->node($dom, 'RECIPE_STEP');
        foreach ($item['parts'] as $part) {
            $step->appendChild(isset($part['timer'])
                ? $this->node($dom, 'RECIPE_TIMER', ['s' => (string) $part['timer'], 'label' => $this->duration($part['timer'])])
                : $dom->createTextNode($part['text']));
        }

        return $step;
    }

    /** @param array<string, ?string> $attributes */
    private function node(\DOMDocument $dom, string $name, array $attributes = [], ?string $text = null): \DOMElement
    {
        $node = $dom->createElement($name);
        foreach ($attributes as $key => $value) {
            $this->set($node, $key, $value);
        }
        if ($text !== null) {
            $node->appendChild($dom->createTextNode($text));
        }

        return $node;
    }

    private function set(\DOMElement $element, string $name, ?string $value): void
    {
        if ($value !== null && $value !== '') {
            $element->setAttribute($name, $value);
        }
    }

    private function duration(int $seconds): string
    {
        return $this->labels->duration($seconds);
    }

    private function t(string $key): string
    {
        return $this->labels->get($key);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }
}
