<?php

namespace ErnestDefoe\Recipes\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Formatter\Formatter;
use Flarum\Post\Post;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Recipes on a real forum: the formatter as the container builds it beside
 * the extensions Recipes sits with, the API a composer posts to, and the
 * discussion page a search engine reads.
 */
class RecipesTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private const SOUP = "[recipe]\ntitle: Soup & bread\nservings: 2\ncook: 20m\n\n[ingredients]\n1 onion\n[/ingredients]\n[steps]\nSimmer [20m].\n[/steps]\n[/recipe]";

    /** The parsed XML of a block, as s9e stores it — Render reads the text. */
    private static function stored(string $body): string
    {
        return '<r><RECIPE><s>[recipe]</s>'.htmlspecialchars($body, ENT_XML1).'<e>[/recipe]</e></RECIPE></r>';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $old = Carbon::now()->subDay();

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [
                ['id' => 1, 'title' => 'Soup night', 'created_at' => $old, 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 2, 'last_post_number' => 2],
                ['id' => 2, 'title' => 'Chat', 'created_at' => $old, 'user_id' => 2, 'first_post_id' => 3, 'comment_count' => 2, 'last_post_number' => 2],
                ['id' => 3, 'title' => 'Hidden start', 'created_at' => $old, 'user_id' => 2, 'first_post_id' => 5, 'comment_count' => 1, 'last_post_number' => 1],
                ['id' => 4, 'title' => 'Private', 'created_at' => $old, 'user_id' => 2, 'first_post_id' => 6, 'comment_count' => 1, 'last_post_number' => 1, 'is_private' => true],
            ],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => $old, 'user_id' => 2, 'type' => 'comment', 'content' => self::stored("title: Soup\nservings: 2\nprep: 5m\ncook: 20m\n\n[ingredients]\n1 onion\n[/ingredients]\n[steps]\nSimmer [20m].\n[/steps]")],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => $old, 'user_id' => 2, 'type' => 'comment', 'content' => self::stored('title: Second recipe')],
                ['id' => 3, 'discussion_id' => 2, 'number' => 1, 'created_at' => $old, 'user_id' => 2, 'type' => 'comment', 'content' => '<t>Just talk</t>'],
                ['id' => 4, 'discussion_id' => 2, 'number' => 2, 'created_at' => $old, 'user_id' => 2, 'type' => 'comment', 'content' => self::stored('title: Reply recipe')],
                ['id' => 5, 'discussion_id' => 3, 'number' => 1, 'created_at' => $old, 'user_id' => 2, 'type' => 'comment', 'content' => self::stored('title: Withheld'), 'hidden_at' => $old, 'hidden_user_id' => 1],
                ['id' => 6, 'discussion_id' => 4, 'number' => 1, 'created_at' => $old, 'user_id' => 2, 'type' => 'comment', 'content' => self::stored('title: Secret'), 'is_private' => true],
            ],
        ]);
    }

    private function render(string $text): string
    {
        $formatter = $this->app()->getContainer()->make(Formatter::class);

        return $formatter->render($formatter->parse($text));
    }

    private function page(int $id, ?int $as = null): array
    {
        $response = $this->send($this->request('GET', '/d/'.$id, $as ? ['authenticatedAs' => $as] : []));
        $html = (string) $response->getBody();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $recipes = array_values(array_filter(array_map(fn ($json) => json_decode($json, true), $m[1]), fn ($d) => ($d['@type'] ?? null) === 'Recipe'));

        return [$response->getStatusCode(), $recipes];
    }

    #[Test]
    public function a_markdown_forum_renders_the_card_and_keeps_its_other_formatting()
    {
        $this->extension('flarum-markdown', 'flarum-bbcode', 'ernestdefoe-recipes');

        $out = $this->render("**bold** [b]bb[/b]\n\n".self::SOUP);

        $this->assertStringContainsString('<strong>bold</strong>', $out);
        $this->assertStringContainsString('<b>bb</b>', $out);
        $this->assertStringContainsString('<h3 class="Recipe-title">Soup &amp; bread</h3>', $out);
        $this->assertStringContainsString('<dt>Serves</dt>', $out, 'labels come from the locale file');
        $this->assertStringContainsString('<dd class="Recipe-cook">20 min</dd>', $out);
        $this->assertStringContainsString('<span class="Recipe-timer" data-seconds="1200">20 min</span>', $out);
    }

    #[Test]
    public function a_plain_forum_without_markdown_or_bbcode_still_reads_the_block()
    {
        $this->extension('ernestdefoe-recipes');

        $this->assertStringContainsString('<h3 class="Recipe-title">Soup &amp; bread</h3>', $this->render(self::SOUP));
    }

    #[Test]
    public function scribe_stores_an_article_and_gets_the_same_card()
    {
        $this->extension('ernestdefoe-scribe', 'ernestdefoe-recipes');

        $out = $this->render("<p><strong>Hi</strong></p><article>title: Soup &amp; bread\nservings: 2\n\n[ingredients]\n1 ½ cups stock\n[/ingredients]</article>");

        $this->assertStringContainsString('<p><strong>Hi</strong></p>', $out);
        $this->assertStringContainsString('<h3 class="Recipe-title">Soup &amp; bread</h3>', $out);
        $this->assertStringContainsString('data-q="1.5"', $out);
    }

    #[Test]
    public function a_post_made_through_the_api_carries_the_card()
    {
        $this->extension('flarum-markdown', 'ernestdefoe-recipes');

        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => self::SOUP], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '2']]]]],
        ]));

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $html = json_decode((string) $response->getBody(), true)['data']['attributes']['contentHtml'];
        $this->assertStringContainsString('class="Recipe" data-servings="2"', $html);
    }

    #[Test]
    public function the_discussion_page_describes_its_first_posts_recipe_once()
    {
        $this->extension('ernestdefoe-recipes');

        [$status, $recipes] = $this->page(1);

        $this->assertSame(200, $status);
        $this->assertCount(1, $recipes, 'the reply\'s recipe is not the page\'s');
        $this->assertSame('Soup', $recipes[0]['name']);
        $this->assertSame('PT25M', $recipes[0]['totalTime']);
        $this->assertSame(['1 onion'], $recipes[0]['recipeIngredient']);
        $this->assertSame([['@type' => 'HowToStep', 'text' => 'Simmer 20 min.']], $recipes[0]['recipeInstructions']);
        $this->assertSame('normal', $recipes[0]['author']['name']);
    }

    #[Test]
    public function a_recipe_only_in_a_reply_is_not_the_pages()
    {
        $this->extension('ernestdefoe-recipes');

        $this->assertSame([], $this->page(2)[1]);
    }

    #[Test]
    public function a_hidden_first_post_is_not_described_to_those_who_cannot_see_it()
    {
        $this->extension('ernestdefoe-recipes');

        $this->assertSame([], $this->page(3)[1], 'guest');
        $this->assertSame('Withheld', $this->page(3, 1)[1][0]['name'] ?? null, 'a moderator sees what they can see');
    }

    #[Test]
    public function a_private_discussion_says_nothing()
    {
        $this->extension('ernestdefoe-recipes');

        [$status, $recipes] = $this->page(4);

        $this->assertNotSame(200, $status);
        $this->assertSame([], $recipes);
    }
}
