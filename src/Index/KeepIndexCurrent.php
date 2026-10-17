<?php

namespace ErnestDefoe\Recipes\Index;

use ErnestDefoe\Recipes\Formatter\Blocks;
use Flarum\Post\CommentPost;
use Flarum\Post\Event\Deleted;
use Flarum\Post\Event\Posted;
use Flarum\Post\Event\Revised;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * A post is (re)indexed when it is written or edited, and its rows go with it.
 *
 * Hiding a post or a discussion changes nothing here: who may SEE a recipe is
 * decided when it is read, by post and discussion visibility (RecipeQuery), so
 * the index never has to agree with a permission.
 */
class KeepIndexCurrent
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        // A new post without a recipe has no rows to replace: no query at all.
        $events->listen(Posted::class, fn (Posted $e) => Blocks::has((string) $e->post->getParsedContentAttribute()) && $this->index($e->post));
        $events->listen(Revised::class, fn (Revised $e) => $this->index($e->post));
        // The foreign key cascades too; this covers a database that ignores it (SQLite).
        $events->listen(Deleted::class, fn (Deleted $e) => $this->db->table('recipe_cards')->where('post_id', $e->post->id)->delete());
    }

    private function index(CommentPost $post): bool
    {
        Indexer::sync($this->db, (int) $post->id, (string) $post->getParsedContentAttribute());

        return true;
    }
}
