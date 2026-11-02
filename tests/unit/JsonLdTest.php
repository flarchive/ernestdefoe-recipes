<?php

namespace ErnestDefoe\Recipes\Tests\unit;

use ErnestDefoe\Recipes\Formatter\Labels;
use ErnestDefoe\Recipes\Recipe;
use ErnestDefoe\Recipes\Seo\RecipeJsonLd;
use Flarum\Locale\TranslatorInterface;
use PHPUnit\Framework\TestCase;

class JsonLdTest extends TestCase
{
    private function fields(): \ErnestDefoe\Recipes\Fields
    {
        $settings = $this->createStub(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->method('get')->willReturn(json_encode([
            ['key' => 'kcal', 'label' => 'Calories', 'unit' => 'kcal', 'scope' => 'recipe', 'schema' => 'calories'],
            ['key' => 'protein', 'label' => 'Protein', 'unit' => 'g', 'scope' => 'serving', 'schema' => 'protein'],
            ['key' => 'phe', 'label' => 'PHE', 'unit' => 'mg', 'scope' => 'serving'],
        ]));

        return new \ErnestDefoe\Recipes\Fields($settings);
    }

    public function test_nutrition_is_per_serving_and_only_for_mapped_fields(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $jsonLd = new RecipeJsonLd(new Labels($translator), $this->createStub(\Flarum\Http\UrlGenerator::class), $this->createStub(\Flarum\Http\SlugManager::class), $this->fields());

        $data = $jsonLd->schema(Recipe::parse("title: Soup\nservings: 4\nkcal: 1000\nprotein: 12.5\nphe: 340", ['kcal', 'protein', 'phe']), null, null, '');
        $this->assertSame(['@type' => 'NutritionInformation', 'calories' => '250 kcal', 'proteinContent' => '12.5 g'], $data['nutrition']);

        $noServings = $jsonLd->schema(Recipe::parse("title: Soup\nkcal: 1000\nprotein: 12.5", ['kcal', 'protein']), null, null, '');
        $this->assertSame(['@type' => 'NutritionInformation', 'proteinContent' => '12.5 g'], $noServings['nutrition'], 'a whole-recipe value with no servings to divide by is left out');

        $this->assertArrayNotHasKey('nutrition', $jsonLd->schema(Recipe::parse('title: Plain'), null, null, ''));
    }

    public function test_schema_org_recipe(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn (?string $id, array $p = []) => ($p['count'] ?? '').' min');

        $recipe = Recipe::parse("title: Soup\nservings: 2\nprep: 5m\ncook: 1h\nimage: https://e.com/s.jpg\n\nWarm.\n\n[ingredients]\n1 onion\nFor the stock:\n1 l water\n[/ingredients]\n[steps]\nChop.\nTo finish:\nSimmer for [20m].\n[/steps]");

        $data = (new RecipeJsonLd(new Labels($translator), $this->createStub(\Flarum\Http\UrlGenerator::class), $this->createStub(\Flarum\Http\SlugManager::class), $this->fields()))->schema($recipe, 'Ernest', '2026-10-16T10:00:00+00:00', 'Fallback');

        $this->assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'Recipe',
            'name' => 'Soup',
            'image' => ['https://e.com/s.jpg'],
            'description' => 'Warm.',
            'author' => ['@type' => 'Person', 'name' => 'Ernest'],
            'datePublished' => '2026-10-16T10:00:00+00:00',
            'recipeYield' => '2',
            'prepTime' => 'PT5M',
            'cookTime' => 'PT1H',
            'totalTime' => 'PT1H5M',
            'recipeIngredient' => ['1 onion', '1 l water'],
            'recipeInstructions' => [
                ['@type' => 'HowToStep', 'text' => 'Chop.'],
                ['@type' => 'HowToSection', 'name' => 'To finish', 'itemListElement' => [
                    ['@type' => 'HowToStep', 'text' => 'Simmer for 20 min.'],
                ]],
            ],
        ], $data);
    }

    public function test_untitled_recipe_takes_the_page_title(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $data = (new RecipeJsonLd(new Labels($translator), $this->createStub(\Flarum\Http\UrlGenerator::class), $this->createStub(\Flarum\Http\SlugManager::class), $this->fields()))->schema(Recipe::parse("[steps]\nStir\n[/steps]"), null, null, 'Grandma’s stew');

        $this->assertSame('Grandma’s stew', $data['name']);
        $this->assertArrayNotHasKey('author', $data);
    }
}
