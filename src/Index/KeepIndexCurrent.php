<?php

namespace ErnestDefoe\Recipes\Index;

use ErnestDefoe\Recipes\Formatter\Blocks;
use ErnestDefoe\Recipes\Photos\Photos;
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
    public function __construct(private readonly ConnectionInterface $db, private readonly Photos $photos)
    {
    }

    public function subscribe(Dispatcher $events): void
    {
        // 🚨 Every listener returns nothing. Laravel's dispatcher stops at a
        // listener that returns false, so `has() && index()` silenced every
        // extension after this one for each post without a recipe.
        $events->listen(Posted::class, function (Posted $e): void {
            // A new post without a recipe has no rows to replace: no query at all.
            if (Blocks::has((string) $e->post->getParsedContentAttribute())) {
                $this->index($e->post);
            }
        });
        $events->listen(Revised::class, function (Revised $e): void {
            $this->index($e->post);
        });
        // The foreign key cascades too; this covers a database that ignores it (SQLite).
        $events->listen(Deleted::class, function (Deleted $e): void {
            $this->db->table('recipe_cards')->where('post_id', $e->post->id)->delete();
            $this->photos->release((int) $e->post->id);
        });
    }

    private function index(CommentPost $post): void
    {
        $id = (int) $post->id;
        $recipes = Indexer::sync($this->db, $id, (string) $post->getParsedContentAttribute());

        // Photos follow the index: the ones this post shows are its own, the
        // ones it stopped showing go unless another recipe still shows them.
        $names = $this->photos->namesIn(array_map(fn ($recipe) => $recipe->image, $recipes));
        $this->photos->release($id, $names);
        $this->photos->attach($id, $names);
    }
}
