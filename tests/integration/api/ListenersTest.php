<?php

namespace ErnestDefoe\Recipes\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * 🚨 A listener that returns false stops Laravel's dispatcher: every listener
 * after it never runs. Recipes shares Posted/Revised with every extension that
 * reacts to a post, so it must stay silent for a post without a recipe.
 */
class ListenersTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    private array $heard = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-recipes');
        $this->extend(
            (new Extend\Event())
                ->listen(Posted::class, function () { $this->heard[] = 'posted'; })
                ->listen(Revised::class, function () { $this->heard[] = 'revised'; })
        );

        $this->prepareDatabase([
            User::class => [$this->normalUser()],
            Discussion::class => [['id' => 1, 'title' => 'Chat', 'created_at' => Carbon::now()->subDay(), 'user_id' => 2, 'first_post_id' => 1, 'comment_count' => 1, 'last_post_number' => 1]],
            'posts' => [['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now()->subDay(), 'user_id' => 2, 'type' => 'comment', 'content' => '<t>Hello</t>']],
        ]);
    }

    #[Test]
    public function a_post_without_a_recipe_leaves_the_other_listeners_running()
    {
        $response = $this->send($this->request('POST', '/api/posts', [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'attributes' => ['content' => 'Just talk, no recipe.'], 'relationships' => ['discussion' => ['data' => ['type' => 'discussions', 'id' => '1']]]]],
        ]));
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $id = json_decode((string) $response->getBody(), true)['data']['id'];
        $response = $this->send($this->request('PATCH', '/api/posts/'.$id, [
            'authenticatedAs' => 1,
            'json' => ['data' => ['type' => 'posts', 'id' => $id, 'attributes' => ['content' => 'Still no recipe.']]],
        ]));
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $this->assertSame(['posted', 'revised'], $this->heard);
    }
}
