<?php

namespace ErnestDefoe\Recipes\Tests\integration\api;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\Testing\integration\ConsoleTestCase;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\Attributes\Test;

class ReindexCommandTest extends ConsoleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-recipes');

        $this->prepareDatabase([
            Discussion::class => [['id' => 1, 'title' => 'Soup', 'created_at' => Carbon::now(), 'user_id' => 1, 'first_post_id' => 1, 'comment_count' => 2]],
            Post::class => [
                ['id' => 1, 'discussion_id' => 1, 'number' => 1, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => "<r><RECIPE><s>[recipe]</s>title: One\n<e>[/recipe]</e></RECIPE><RECIPE><s>[recipe]</s>title: Two\n<e>[/recipe]</e></RECIPE></r>"],
                ['id' => 2, 'discussion_id' => 1, 'number' => 2, 'created_at' => Carbon::now(), 'user_id' => 1, 'type' => 'comment', 'content' => '<t>Talk about a RECIPE</t>'],
            ],
        ]);
    }

    #[Test]
    public function it_rebuilds_the_index_from_the_posts()
    {
        $db = $this->app()->getContainer()->make(ConnectionInterface::class);
        $db->table('recipe_cards')->insert(['post_id' => 2, 'position' => 0, 'title' => 'Stale']);

        $this->assertSame('Indexed 1 posts with recipes.', $this->runCommand(['command' => 'recipes:reindex']));

        $this->assertSame(['One', 'Two'], $db->table('recipe_cards')->orderBy('position')->pluck('title')->all());
    }
}
