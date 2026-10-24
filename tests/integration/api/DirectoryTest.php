<?php

namespace ErnestDefoe\Recipes\Tests\integration\api;

use Carbon\Carbon;
use ErnestDefoe\Recipes\Index\Indexer;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Tags\Tag;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * The directory and variant links, as each kind of visitor sees them.
 *
 * Posts are seeded straight into the database and then indexed by the same
 * backfill the 1.1.0 migration runs, so these also prove the backfill.
 */
class DirectoryTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private static function stored(string $body): string
    {
        return '<r><RECIPE><s>[recipe]</s>'.htmlspecialchars($body, ENT_XML1).'<e>[/recipe]</e></RECIPE></r>';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('flarum-tags', 'ernestdefoe-recipes');

        $old = Carbon::now()->subDay();
        $d = fn (int $id, string $title, array $extra = []) => ['id' => $id, 'title' => $title, 'slug' => str_replace(' ', '-', strtolower($title)), 'created_at' => $old, 'user_id' => 2, 'first_post_id' => $id * 10 + 1, 'comment_count' => 2, 'last_post_number' => 2] + $extra;
        $p = fn (int $id, int $discussion, int $number, string $body, array $extra = []) => ['id' => $id, 'discussion_id' => $discussion, 'number' => $number, 'created_at' => $old, 'user_id' => 2, 'type' => 'comment', 'content' => self::stored($body)] + $extra;

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Tag::class => [
                ['id' => 1, 'name' => 'Food', 'slug' => 'food', 'position' => 0],
                ['id' => 2, 'name' => 'Staff', 'slug' => 'staff', 'position' => 1, 'is_restricted' => true],
            ],
            Discussion::class => [
                $d(1, 'Soup night'),
                $d(2, 'Private', ['is_private' => true]),
                $d(3, 'Staff kitchen'),
                $d(4, 'Hidden start'),
                $d(5, 'My vegan version'),
            ],
            'discussion_tag' => [
                ['discussion_id' => 1, 'tag_id' => 1],
                ['discussion_id' => 3, 'tag_id' => 2],
            ],
            Post::class => [
                $p(11, 1, 1, "title: Tomato soup\nservings: 4\ncook: 20m"),
                $p(12, 1, 2, "title: Vegan tomato soup\nbased-on: 11"),
                $p(21, 2, 1, "title: Secret soup", ['is_private' => true]),
                $p(31, 3, 1, "title: Staff stew"),
                $p(41, 4, 1, "title: Withheld", ['hidden_at' => $old, 'hidden_user_id' => 1]),
                $p(51, 5, 1, "title: Smoky soup\nbased-on: 11"),
                $p(53, 5, 3, "title: 50% rye loaf"),
                $p(54, 5, 4, "title: snake_case stew"),
                ['id' => 52, 'discussion_id' => 5, 'number' => 2, 'created_at' => $old, 'user_id' => 2, 'type' => 'comment', 'content' => '<t>100% delicious_soup</t>'],
            ],
        ]);
    }

    private function backfill(): void
    {
        Indexer::backfill($this->app()->getContainer()->make(ConnectionInterface::class));
    }

    /** A GET whose query string reaches the handler: the test client does not parse one from the path. */
    private function get(string $uri, ?int $as = null): \Psr\Http\Message\ServerRequestInterface
    {
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        parse_str($query, $params);

        return $this->request('GET', $path, $as ? ['authenticatedAs' => $as] : [])->withQueryParams($params);
    }

    /** @return list<string> */
    private function titles(string $query, ?int $as = null): array
    {
        $response = $this->send($this->get('/api/recipes'.$query, $as));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $titles = array_column(json_decode((string) $response->getBody(), true)['data'], 'title');
        sort($titles);

        return $titles;
    }

    #[Test]
    public function the_directory_lists_only_what_the_visitor_may_see()
    {
        $this->backfill();

        $this->assertSame(['50% rye loaf', 'Smoky soup', 'Tomato soup', 'Vegan tomato soup', 'snake_case stew'], $this->titles(''), 'guest');
        // A private discussion is private from an admin too, until an extension
        // (Byobu) grants otherwise; hidden posts and restricted tags are not.
        $this->assertSame(['50% rye loaf', 'Smoky soup', 'Staff stew', 'Tomato soup', 'Vegan tomato soup', 'Withheld', 'snake_case stew'], $this->titles('', 1), 'admin');
    }

    #[Test]
    public function search_and_tag_filter()
    {
        $this->backfill();

        $this->assertSame(['Vegan tomato soup'], $this->titles('?filter[q]=vegan'));
        $this->assertSame(['Vegan tomato soup'], $this->titles('?filter[q]=VEGAN'), 'any case, on every database');
        $this->assertSame(['50% rye loaf'], $this->titles('?filter[q]='.rawurlencode('50%')), 'a % is a character, not a wildcard');
        $this->assertSame(['snake_case stew'], $this->titles('?filter[q]=e_c'), 'and so is an underscore');
        $this->assertSame([], $this->titles('?filter[q]='.rawurlencode('100%')), 'a % is a character, not a wildcard');
        $this->assertSame(['Tomato soup', 'Vegan tomato soup'], $this->titles('?filter[tag]=food'));
        $this->assertSame([], $this->titles('?filter[tag]=staff'), 'a restricted tag answers nothing to a guest');
        $this->assertSame(['Staff stew'], $this->titles('?filter[tag]=staff', 1));
    }

    #[Test]
    public function paging_says_whether_there_is_more()
    {
        $this->backfill();

        $first = json_decode((string) $this->send($this->get('/api/recipes?page[limit]=2'))->getBody(), true);
        $rest = json_decode((string) $this->send($this->get('/api/recipes?page[limit]=2&page[offset]=2'))->getBody(), true);

        $this->assertCount(2, $first['data']);
        $this->assertTrue($first['more']);
        $last = json_decode((string) $this->send($this->get('/api/recipes?page[limit]=2&page[offset]=4'))->getBody(), true);
        $this->assertCount(2, $rest['data']);
        $this->assertTrue($rest['more']);
        $this->assertCount(1, $last['data']);
        $this->assertFalse($last['more']);
    }

    #[Test]
    public function related_returns_variants_and_originals_for_the_cards_on_a_page()
    {
        $this->backfill();

        $this->assertSame(['Smoky soup', 'Tomato soup', 'Vegan tomato soup'], $this->titles('?filter[related]=11'));
        // A variant's card asks about itself and its original together, as forum/related.ts does.
        $this->assertSame(['Smoky soup', 'Tomato soup', 'Vegan tomato soup'], $this->titles('?filter[related]=12,11'), 'a variant finds its original');
        $this->assertSame([], $this->titles('?filter[related]=21'), 'a private post stays private');
    }

    #[Test]
    public function the_index_follows_posts_as_they_are_written_edited_and_deleted()
    {
        $id = null;
        $count = function () use (&$id) {
            return $this->app()->getContainer()->make(ConnectionInterface::class)->table('recipe_cards')->where('post_id', $id)->count();
        };

        $made = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => "[recipe]\ntitle: Fresh one\n[/recipe]"], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertSame(201, $made->getStatusCode(), (string) $made->getBody());
        $id = json_decode((string) $made->getBody(), true)['data']['id'];

        $this->assertSame(1, $count());
        $this->assertContains('Fresh one', $this->titles('?filter[related]='.$id));

        $edit = $this->send($this->request('PATCH', '/api/posts/'.$id, [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => $id, 'attributes' => ['content' => "[recipe]\ntitle: Renamed\n[/recipe]"]]],
        ]));
        $this->assertSame(200, $edit->getStatusCode(), (string) $edit->getBody());
        $this->assertSame(['Renamed'], $this->titles('?filter[q]=Renamed'));

        $this->send($this->request('PATCH', '/api/posts/'.$id, [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => $id, 'attributes' => ['content' => 'No recipe any more']]],
        ]));
        $this->assertSame(0, $count());

        $this->send($this->request('PATCH', '/api/posts/'.$id, [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => $id, 'attributes' => ['content' => "[recipe]\ntitle: Back\n[/recipe]"]]],
        ]));
        $this->assertSame(1, $count());

        $this->assertSame(204, $this->send($this->request('DELETE', '/api/posts/'.$id, ['authenticatedAs' => 1]))->getStatusCode());
        $this->assertSame(0, $count());
    }

    #[Test]
    public function based_on_links_resolve_as_the_visitor()
    {
        $this->assertSame(302, ($r = $this->send($this->request('GET', '/recipes/from/11')))->getStatusCode());
        $this->assertStringContainsString('/d/1-soup-night/1', $r->getHeaderLine('Location'));

        $this->assertSame(404, $this->send($this->request('GET', '/recipes/from/21'))->getStatusCode(), 'private');
        $this->assertSame(404, $this->send($this->request('GET', '/recipes/from/41'))->getStatusCode(), 'hidden');
        $this->assertSame(404, $this->send($this->request('GET', '/recipes/from/9999'))->getStatusCode(), 'gone');
    }

    #[Test]
    public function a_variant_says_what_it_is_based_on_to_search_engines()
    {
        $this->backfill();

        $html = (string) $this->send($this->request('GET', '/d/5'))->getBody();
        preg_match('#<script type="application/ld\+json">(\{"@context":"https://schema.org","@type":"Recipe".*?)</script>#s', $html, $m);

        // The card's own link is the forum's full address (subfolder installs included).
        $this->assertStringContainsString('<a href="http://localhost/recipes/from/11">', $html);

        $data = json_decode($m[1] ?? '{}', true);
        $this->assertSame('Smoky soup', $data['name'] ?? null);
        $this->assertStringContainsString('/d/1-soup-night/1', $data['isBasedOn'] ?? '');
    }

    #[Test]
    public function the_recipes_page_lists_recipes_for_readers_without_javascript()
    {
        $this->backfill();

        $html = (string) $this->send($this->request('GET', '/recipes'))->getBody();

        $this->assertStringContainsString('Tomato soup</a>', $html);
        $this->assertStringContainsString('/d/1-soup-night/1', $html);
        $this->assertStringNotContainsString('Secret soup', $html);
        $this->assertStringNotContainsString('Staff stew', $html);
    }
}
