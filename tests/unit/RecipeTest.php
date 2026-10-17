<?php

namespace ErnestDefoe\Recipes\Tests\unit;

use ErnestDefoe\Recipes\Recipe;
use PHPUnit\Framework\TestCase;

class RecipeTest extends TestCase
{
    private const BODY = <<<'TXT'

title: Fluffy pancakes
servings: 4
prep: 10m
cook: 20m
image: https://example.com/p.jpg

A weekend breakfast.
Takes less time than the coffee.

[ingredients]
- 1 1/2 cups flour
* 2 tbsp sugar
For the topping:
200 g blueberries
[/ingredients]

[steps]
1. Whisk the dry ingredients.
2) Cook each side [2m] until golden, then rest [30s].
[/steps]
TXT;

    public function test_reads_every_part(): void
    {
        $recipe = Recipe::parse(self::BODY);

        $this->assertSame('Fluffy pancakes', $recipe->title);
        $this->assertSame(4, $recipe->servings);
        $this->assertSame(600, $recipe->prep);
        $this->assertSame(1200, $recipe->cook);
        $this->assertSame(1800, $recipe->total());
        $this->assertSame('https://example.com/p.jpg', $recipe->image);
        $this->assertSame("A weekend breakfast.\nTakes less time than the coffee.", $recipe->description);

        $this->assertCount(4, $recipe->ingredients);
        $this->assertSame('1 1/2 cups flour', $recipe->ingredients[0]['text'] ?? null, 'bullets are dropped');
        $this->assertSame('2 tbsp sugar', $recipe->ingredients[1]['text'] ?? null);
        $this->assertSame(['heading' => 'For the topping'], $recipe->ingredients[2]);

        $this->assertSame([['text' => 'Whisk the dry ingredients.']], $recipe->steps[0]['parts'] ?? null, 'numbers are dropped');
        $this->assertSame([
            ['text' => 'Cook each side '],
            ['timer' => 120],
            ['text' => ' until golden, then rest '],
            ['timer' => 30],
            ['text' => '.'],
        ], $recipe->steps[1]['parts'] ?? null);
    }

    public function test_a_bracket_that_is_not_a_time_stays_text(): void
    {
        $recipe = Recipe::parse("[steps]\nAdd salt [to taste] and [0m] nothing.\n[/steps]");

        $this->assertSame([['text' => 'Add salt [to taste] and [0m] nothing.']], $recipe->steps[0]['parts'] ?? null);
    }

    public function test_only_web_addresses_are_images(): void
    {
        foreach (['javascript:alert(1)', 'data:image/png;base64,AAAA', '//evil.example/x.png', 'https://x.com/a b.png', 'ftp://x.com/a.png', "\x01javascript:alert(1)", 'https://x.com/"onerror="alert(1)'] as $url) {
            $this->assertNull(Recipe::parse("image: $url")->image, $url);
        }
    }

    public function test_a_number_ingredient_line_keeps_its_number(): void
    {
        // Numbered lists are steps; a numbered ingredient is an amount.
        $recipe = Recipe::parse("[ingredients]\n2. eggs\n[/ingredients]");

        $this->assertSame('2. eggs', $recipe->ingredients[0]['text'] ?? null);
    }

    public function test_limits(): void
    {
        $recipe = Recipe::parse("title: ".str_repeat('x', 500)."\nservings: 5000\n\n[ingredients]\n".str_repeat("1 egg\n", 300).'[/ingredients]');

        $this->assertSame(Recipe::MAX_TITLE + 1, mb_strlen($recipe->title), 'clipped, with an ellipsis');
        $this->assertNull($recipe->servings);
        $this->assertCount(Recipe::MAX_ITEMS, $recipe->ingredients);
    }

    public function test_without_a_header_the_text_is_the_description(): void
    {
        $recipe = Recipe::parse("Just a note\n[steps]\nStir\n[/steps]");

        $this->assertSame('', $recipe->title);
        $this->assertSame('Just a note', $recipe->description);
        $this->assertFalse($recipe->isEmpty());
        $this->assertTrue(Recipe::parse("only words\n")->isEmpty());
    }

    public function test_based_on_is_a_post_id_or_nothing(): void
    {
        $this->assertSame(42, Recipe::parse("title: Vegan pancakes\nbased-on: 42")->basedOn);
        $this->assertSame(42, Recipe::parse("Based-On:  42 ")->basedOn);

        foreach (['0', '-3', 'abc', '42; DROP', 'https://example.com/d/1', ''] as $value) {
            $this->assertNull(Recipe::parse("based-on: $value")->basedOn, $value);
        }
    }
}
