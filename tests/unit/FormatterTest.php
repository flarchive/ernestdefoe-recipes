<?php

namespace ErnestDefoe\Recipes\Tests\unit;

use ErnestDefoe\Recipes\Formatter\Configure;
use ErnestDefoe\Recipes\Formatter\Labels;
use ErnestDefoe\Recipes\Formatter\Render;
use Flarum\Extension\ExtensionManager;
use Flarum\Locale\TranslatorInterface;
use PHPUnit\Framework\TestCase;
use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Unparser;

/**
 * A post with a recipe in it, through the real s9e parser and renderer, with
 * the plugins a forum actually runs beside it.
 *
 * 🚨 Not unit tests of a function. Every way a recipe block breaks is an
 * interaction: Autolink grabbing a URL, Emoticons grabbing ":)", HTMLEntities
 * grabbing "&amp;", Litedown turning ingredients into paragraphs. A test that
 * configured RECIPE alone would pass while forums showed the wreckage.
 */
class FormatterTest extends TestCase
{
    /** @return array{0: \s9e\TextFormatter\Parser, 1: \s9e\TextFormatter\Renderer} */
    private function formatter(bool $scribe): array
    {
        $config = new Configurator();
        $config->rootRules->enableAutoLineBreaks();
        $config->plugins->load('Autolink');
        $config->plugins->load('Escaper');
        $config->plugins->load('Emoticons')->add(':)', '<img alt=":)" src="smile.png"/>');
        $config->BBCodes->addFromRepository('B');

        if ($scribe) {
            $config->HTMLElements->allowElement('p');
            $config->HTMLElements->allowElement('strong');
            $config->plugins->load('HTMLEntities');
        } else {
            $config->plugins->load('Litedown');
        }

        $extensions = $this->createStub(ExtensionManager::class);
        $extensions->method('isEnabled')->willReturnCallback(fn (string $id) => $scribe && $id === 'ernestdefoe-scribe');

        (new Configure($extensions))($config);

        $objects = $config->finalize();

        return [$objects['parser'], $objects['renderer']];
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            fn (?string $id, array $parameters = []) => substr((string) $id, strlen('ernestdefoe-recipes.forum.')).(isset($parameters['count']) ? '='.$parameters['count'] : '')
        );

        return $translator;
    }

    private function fields(): \ErnestDefoe\Recipes\Fields
    {
        $settings = $this->createStub(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->method('get')->willReturn(json_encode([
            ['key' => 'protein', 'label' => 'Protein', 'unit' => 'g', 'scope' => 'serving', 'schema' => 'protein'],
            ['key' => 'phe', 'label' => 'PHE', 'unit' => 'mg', 'scope' => 'recipe'],
        ]));

        return new \ErnestDefoe\Recipes\Fields($settings);
    }

    private function urls(): \Flarum\Http\UrlGenerator
    {
        $routes = $this->createStub(\Flarum\Http\RouteCollectionUrlGenerator::class);
        $routes->method('route')->willReturnCallback(fn (string $name, array $p = []) => '/recipes/from/'.($p['id'] ?? ''));
        $url = $this->createStub(\Flarum\Http\UrlGenerator::class);
        $url->method('to')->willReturn($routes);

        return $url;
    }

    private function render(string $text, bool $scribe = false): string
    {
        [$parser, $renderer] = $this->formatter($scribe);
        $xml = $parser->parse($text);

        return $renderer->render((new Render(new Labels($this->translator()), $this->urls(), $this->fields()))($renderer, null, $xml));
    }

    private const MARKDOWN = <<<'TXT'
Before **it**.

[recipe]
title: Mac & Cheese :) https://example.com/x
servings: 4
prep: 10m
cook: 1h 5m
image: https://example.com/mac.jpg?a=1&b=2

Creamy, *quick*.

[ingredients]
- 1 1/2 cups macaroni
- 200g cheddar
For the topping:
- salt to taste
[/ingredients]

[steps]
1. Boil the pasta [8m].
2. Bake until golden.
[/steps]
[/recipe]

After [b]it[/b].
TXT;

    public function test_a_markdown_post_renders_the_card(): void
    {
        $out = $this->render(self::MARKDOWN);

        $this->assertStringContainsString('<div class="Recipe" data-servings="4" data-prep="600" data-cook="3900">', $out);
        $this->assertStringContainsString('<img class="Recipe-image" src="https://example.com/mac.jpg?a=1&amp;b=2"', $out);
        $this->assertStringContainsString('<h3 class="Recipe-title">Mac &amp; Cheese :) https://example.com/x</h3>', $out, 'no emoticon, no link, no torn tag');
        $this->assertStringContainsString('<dd class="Recipe-prep">duration.minutes=10</dd>', $out);
        $this->assertStringContainsString('<dd class="Recipe-total">duration.hours=1 duration.minutes=15</dd>', $out);
        $this->assertStringContainsString('<div class="Recipe-description">Creamy, *quick*.</div>', $out, 'plain text inside the block');
        $this->assertStringContainsString('<li class="Recipe-ingredient" data-q="1.5" data-one="cup" data-many="cups"><span class="Recipe-qty">1 1/2</span> <span class="Recipe-unit">cups</span> macaroni</li>', $out);
        $this->assertStringContainsString('<li class="Recipe-ingredient" data-q="200" data-dec="1"><span class="Recipe-qty">200</span><span class="Recipe-unit">g</span> cheddar</li>', $out);
        $this->assertStringContainsString('<li class="Recipe-group">For the topping</li>', $out);
        $this->assertStringContainsString('<li class="Recipe-ingredient">salt to taste</li>', $out);
        $this->assertStringContainsString('<li class="Recipe-step">Boil the pasta <span class="Recipe-timer" data-seconds="480">duration.minutes=8</span>.</li>', $out);

        // The rest of the post is untouched.
        $this->assertStringContainsString('Before <strong>it</strong>.', $out);
        $this->assertStringContainsString('After <b>it</b>.', $out);
        $this->assertStringNotContainsString('[/recipe]', $out);
        $card = substr($out, (int) strpos($out, 'class="Recipe"'));
        $this->assertStringNotContainsString('<p>', substr($card, 0, (int) strpos($card, '</section></div>')), 'no paragraphs inside the card');
    }

    public function test_scribe_html_renders_the_same_card(): void
    {
        $out = $this->render("<p><strong>Hi</strong> &amp; welcome</p><article>title: Mac &amp; Cheese &lt;3 :)\nservings: 2\n\n[ingredients]\n1 ½ cups milk\n[/ingredients]</article>", true);

        $this->assertStringContainsString('<p><strong>Hi</strong> &amp; welcome</p>', $out);
        $this->assertStringContainsString('<h3 class="Recipe-title">Mac &amp; Cheese &lt;3 :)</h3>', $out, 'decoded once, escaped once');
        $this->assertStringContainsString('<span class="Recipe-qty">1 ½</span>', $out);
    }

    public function test_without_scribe_an_article_element_is_just_text(): void
    {
        $this->assertStringNotContainsString('class="Recipe', $this->render('<article>title: x</article>'));
    }

    public function test_nothing_in_a_recipe_becomes_markup(): void
    {
        $out = $this->render("[recipe]\ntitle: <script>alert(1)</script>\nimage: javascript:alert(1)\n\n[steps]\n<img src=x onerror=alert(1)> [5m]\n[/steps]\n[/recipe]");

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('<img src="x"', $out);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);

        $scribe = $this->render('<article>title: &lt;script&gt;alert(1)&lt;/script&gt;<script>alert(2)</script></article>', true);
        $this->assertStringNotContainsString('<script', $scribe);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $scribe);
    }

    public function test_the_stored_text_is_given_back_unchanged_for_editing(): void
    {
        [$parser] = $this->formatter(false);

        $this->assertSame(self::MARKDOWN, Unparser::unparse($parser->parse(self::MARKDOWN)));
    }

    public function test_the_preview_hook_never_reaches_a_rendered_post(): void
    {
        // A block the server cannot read stays as its source, without s9e's preview attribute.
        $out = $this->render("[recipe]\njust words\n[/recipe]");

        $this->assertStringContainsString('<div class="Recipe Recipe--source"><div class="Recipe-source">', $out);
        $this->assertStringNotContainsString('livepreview', $out);
    }

    public function test_two_recipes_in_one_post(): void
    {
        $out = $this->render("[recipe]\ntitle: One\n[/recipe]\n\n[recipe]\ntitle: Two\n[/recipe]");

        $this->assertSame(2, substr_count($out, '<div class="Recipe"'));
        $this->assertStringContainsString('One</h3>', $out);
        $this->assertStringContainsString('Two</h3>', $out);
    }

    public function test_a_post_without_a_recipe_is_not_touched(): void
    {
        [$parser, $renderer] = $this->formatter(false);
        $xml = $parser->parse('Just **a** post with [b]recipe[/b] in it.');

        $this->assertSame($xml, (new Render(new Labels($this->translator()), $this->urls(), $this->fields()))($renderer, null, $xml));
    }

    public function test_a_variant_links_to_its_original_and_carries_raw_times(): void
    {
        $out = $this->render("[recipe]\ntitle: Vegan pancakes\nprep: 10m\ncook: 1h\nbased-on: 42\n[/recipe]");

        $this->assertStringContainsString('data-prep="600" data-cook="3600" data-based-on="42"', $out);
        $this->assertStringContainsString('<p class="Recipe-basedOn"><i class="icon fas fa-code-branch" aria-hidden="true"></i> <a href="/recipes/from/42">based_on</a></p>', $out);
    }

    public function test_the_admins_fields_show_in_their_order_with_units(): void
    {
        $out = $this->render("[recipe]\ntitle: Soup\nservings: 2\nphe: 340\nprotein: 12.50\nretired: 9\n[/recipe]");

        $this->assertStringContainsString(
            '<dl class="Recipe-facts">'
            .'<div class="Recipe-fact" data-key="protein"><dt>Protein</dt><dd><span class="Recipe-factValue" data-q="12.5">12.5</span> <span class="Recipe-factUnit">g</span> <span class="Recipe-factPer">per_serving</span></dd></div>'
            .'<div class="Recipe-fact" data-key="phe"><dt>PHE</dt><dd><span class="Recipe-factValue" data-q="340" data-scale="1">340</span> <span class="Recipe-factUnit">mg</span></dd></div>'
            .'</dl>',
            $out
        );
        $this->assertStringNotContainsString('retired', $out, 'a field the admin removed is not shown');
    }

    public function test_field_labels_are_text_not_markup(): void
    {
        $settings = $this->createStub(\Flarum\Settings\SettingsRepositoryInterface::class);
        $settings->method('get')->willReturn(json_encode([['key' => 'x', 'label' => '<img src=x onerror=alert(1)>', 'unit' => '"><b>']]));
        [$parser, $renderer] = $this->formatter(false);
        $xml = $parser->parse("[recipe]\ntitle: T\nx: 1\n[/recipe]");
        $out = $renderer->render((new Render(new Labels($this->translator()), $this->urls(), new \ErnestDefoe\Recipes\Fields($settings)))($renderer, null, $xml));

        $this->assertStringNotContainsString('<img src=x', $out);
        $this->assertStringNotContainsString('<b>', $out);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $out);
    }
}
