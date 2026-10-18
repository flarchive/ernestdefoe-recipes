<?php

namespace ErnestDefoe\Recipes\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The admin's custom fields on a real forum: what the browser is told, what a
 * card shows, what search engines get.
 */
class FieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-recipes');

        $this->setting('ernestdefoe-recipes.fields', json_encode([
            ['key' => 'kcal', 'label' => 'Calories', 'unit' => 'kcal', 'scope' => 'recipe', 'schema' => 'calories'],
            ['key' => 'phe', 'label' => 'PHE', 'unit' => 'mg', 'scope' => 'serving'],
            ['key' => 'title', 'label' => 'Not allowed'],
            ['key' => 'x', 'label' => '<b>bold</b>', 'secret' => 'never sent'],
        ]));

        $this->prepareDatabase([
            Discussion::class => [['id' => 1, 'title' => 'Soup', 'slug' => 'soup', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 1]],
            Post::class => [[
                'id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment',
                'content' => '<r><RECIPE><s>[recipe]</s>'.htmlspecialchars("title: Soup\nservings: 4\nkcal: 1000\nphe: 120\n", ENT_XML1).'<e>[/recipe]</e></RECIPE></r>',
            ]],
        ]);
    }

    #[Test]
    public function the_forum_is_told_only_valid_fields_and_only_their_definitions()
    {
        $forum = json_decode((string) $this->send($this->request('GET', '/api'))->getBody(), true);

        $this->assertSame([
            ['key' => 'kcal', 'label' => 'Calories', 'unit' => 'kcal', 'scope' => 'recipe', 'schema' => 'calories'],
            ['key' => 'phe', 'label' => 'PHE', 'unit' => 'mg', 'scope' => 'serving', 'schema' => null],
            ['key' => 'x', 'label' => '<b>bold</b>', 'unit' => '', 'scope' => 'serving', 'schema' => null],
        ], $forum['data']['attributes']['recipesFields']);
    }

    #[Test]
    public function the_card_and_the_search_engines_get_the_values()
    {
        $html = (string) $this->send($this->request('GET', '/d/1'))->getBody();

        $this->assertStringContainsString('<span class="Recipe-factValue" data-q="1000" data-scale="1">1000</span> <span class="Recipe-factUnit">kcal</span>', $html);
        $this->assertStringContainsString('<span class="Recipe-factValue" data-q="120">120</span> <span class="Recipe-factUnit">mg</span> <span class="Recipe-factPer">per serving</span>', $html);

        preg_match('#<script type="application/ld\+json">(\{"@context":"https://schema.org","@type":"Recipe".*?)</script>#s', $html, $m);
        $this->assertSame(['@type' => 'NutritionInformation', 'calories' => '250 kcal'], json_decode($m[1] ?? '{}', true)['nutrition'] ?? null);
    }
}
